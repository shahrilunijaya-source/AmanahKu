<?php

namespace Tests\Feature;

use App\Mcp\Tools\CreateCardTool;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkItem;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Feature coverage for the S02 cancel/restore/create surface of
 * docs/build/contracts/dates.md Rule 1. The lock itself (BoardRules::assertDueDateLocked)
 * is covered end to end by tests/Acceptance/DateCalendarRulesTest — this file covers
 * the cancel route, its cascade and audit trail, and the two write surfaces
 * (POST /app/board, MCP create_card) that must now require a due date.
 */
class DueDateLockTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Tenant $tenant;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create(['name' => 'Demo', 'email' => 'demo@example.com', 'password' => Hash::make('password')]);
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $this->user->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $this->employee = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->user->id,
            'name' => 'Demo', 'status' => 'active', 'workload' => 'green',
        ]);
    }

    private function actingInTenant(): self
    {
        $this->actingAs($this->user)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    private function card(array $attrs = []): WorkItem
    {
        return $this->employee->workItems()->create(array_merge([
            'tenant_id' => $this->tenant->id, 'title' => 'X', 'type' => 'task',
            'priority' => 'low', 'status' => 'done', 'progress' => 100,
        ], $attrs));
    }

    public function test_cancel_route_needs_a_reason(): void
    {
        $card = $this->card(['due_at' => '2026-09-30']);

        $this->actingInTenant()
            ->postJson("/app/board/{$card->id}/cancel", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('reason');

        $this->assertNull($card->fresh()->cancelled_at);
    }

    public function test_cancel_cascades_to_children_and_audits_with_reason(): void
    {
        $card = $this->card(['due_at' => '2026-09-30']);
        $child = $card->children()->create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'title' => 'Sub', 'type' => 'task', 'priority' => 'low', 'status' => 'done', 'progress' => 100,
        ]);

        $this->actingInTenant()
            ->postJson("/app/board/{$card->id}/cancel", ['reason' => 'Client moved the milestone'])
            ->assertSuccessful();

        $this->assertNotNull($card->fresh()->cancelled_at);
        $this->assertNotNull($card->fresh()->archived_at);
        $this->assertNotNull($child->fresh()->cancelled_at);
        $this->assertNotNull($child->fresh()->archived_at);

        $parentAudit = AuditLog::where('subject_id', $card->id)->where('field', 'cancelled_at')->latest('id')->first();
        $this->assertNotNull($parentAudit);
        $this->assertSame('Client moved the milestone', $parentAudit->reason);

        $childAudit = AuditLog::where('subject_id', $child->id)->where('field', 'cancelled_at')->latest('id')->first();
        $this->assertNotNull($childAudit, 'the subtask must be audited too, not just the parent');
        $this->assertSame('Client moved the milestone', $childAudit->reason);
    }

    public function test_cancel_refuses_an_already_cancelled_card(): void
    {
        $card = $this->card(['due_at' => '2026-09-30']);

        $this->actingInTenant()
            ->postJson("/app/board/{$card->id}/cancel", ['reason' => 'First reason'])
            ->assertSuccessful();

        $this->actingInTenant()
            ->postJson("/app/board/{$card->id}/cancel", ['reason' => 'Second reason'])
            ->assertStatus(422);
    }

    public function test_restore_of_a_cancelled_card_is_refused(): void
    {
        $card = $this->card(['due_at' => '2026-09-30']);

        $this->actingInTenant()
            ->postJson("/app/board/{$card->id}/cancel", ['reason' => 'Scope removed'])
            ->assertSuccessful();

        $this->actingInTenant()
            ->postJson("/app/board/{$card->id}/restore")
            ->assertStatus(422);

        $this->assertNotNull($card->fresh()->cancelled_at);
    }

    public function test_store_requires_a_due_date_on_a_new_work_card(): void
    {
        $this->actingInTenant()
            ->postJson('/app/board', ['title' => 'No date', 'type' => 'task', 'priority' => 'low'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('due_at');
    }

    public function test_mcp_create_card_requires_due_at(): void
    {
        $token = $this->user->mintApiToken($this->tenant, 'test', ['board:write'])->plainTextToken;
        $headers = ['Authorization' => 'Bearer '.$token];

        Auth::forgetGuards();
        $response = $this->callTool(CreateCardTool::class, [
            'title' => 'No date', 'type' => 'task', 'priority' => 'low',
        ], $headers);

        $this->assertTrue((bool) $response->json('result.isError'));

        app(CurrentTenant::class)->set($this->tenant);
        $this->assertSame(0, WorkItem::where('title', 'No date')->count());
        app(CurrentTenant::class)->set(null);
    }

    /**
     * Rule 2 of docs/build/contracts/dates.md: an Event's date reschedules freely, so
     * the model's `saving` guard (BoardRules::assertDueDateLocked backstop in
     * WorkItem::booted()) must not fire for it.
     */
    public function test_event_rows_are_exempt_from_the_model_guard(): void
    {
        $event = $this->card(['type' => 'event', 'due_at' => '2026-09-30']);

        $event->update(['due_at' => '2026-10-15']);

        $this->assertSame('2026-10-15', $event->fresh()->due_at?->format('Y-m-d'));
    }

    private function callTool(string $toolClass, array $arguments, array $headers): TestResponse
    {
        return $this->postJson('/mcp/amanahku', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => app($toolClass)->name(), 'arguments' => $arguments],
        ], $headers);
    }
}
