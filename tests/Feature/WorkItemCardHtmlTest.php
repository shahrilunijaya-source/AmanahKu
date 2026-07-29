<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Stage 1 of the T.A.A. board redesign: every write response now carries the
 * card's rendered partials.work-card HTML, so the client repaints by swapping
 * markup instead of rebuilding it in JS (cardInner()/buildCardNode() are gone).
 */
class WorkItemCardHtmlTest extends TestCase
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
            'priority' => 'low', 'status' => 'todo', 'progress' => 0,
        ], $attrs));
    }

    public function test_update_returns_html_key_containing_the_updated_title(): void
    {
        $item = $this->card(['title' => 'Original title']);

        $res = $this->actingInTenant()->patchJson("/app/board/{$item->id}", [
            'title' => 'Renamed by the drawer', 'type' => 'task', 'priority' => 'medium',
        ])->assertOk();

        $html = $res->json('html');
        $this->assertIsString($html);
        $this->assertStringContainsString('Renamed by the drawer', $html);
        // The card root keeps every data-* attribute Sortable/the filters read.
        $this->assertStringContainsString('data-card', $html);
        $this->assertStringContainsString('data-id="'.$item->id.'"', $html);
        $this->assertStringContainsString('data-status="todo"', $html);
    }

    public function test_store_returns_html_for_the_new_card(): void
    {
        $res = $this->actingInTenant()->postJson('/app/board', [
            'title' => 'Fresh from the composer', 'type' => 'assignment', 'priority' => 'medium', 'status' => 'prog',
        ])->assertCreated();

        $this->assertStringContainsString('Fresh from the composer', $res->json('html'));
    }

    public function test_move_returns_html_reflecting_the_new_status(): void
    {
        $item = $this->card(['status' => 'todo']);

        $res = $this->actingInTenant()->postJson("/app/board/{$item->id}/move", ['status' => 'done'])->assertOk();

        $this->assertStringContainsString('data-status="done"', $res->json('html'));
    }

    public function test_comment_endpoints_return_html_so_the_card_badge_repaints(): void
    {
        $item = $this->card();

        $created = $this->actingInTenant()->postJson("/app/board/{$item->id}/comments", ['body' => 'Hello'])
            ->assertCreated();
        $this->assertIsString($created->json('html'));
        $commentId = $created->json('comment.id');

        $deleted = $this->actingInTenant()->deleteJson("/app/board/comments/{$commentId}")->assertOk();
        $this->assertIsString($deleted->json('html'));
    }

    public function test_compact_card_partial_omits_estimate_hours_and_uses_the_compact_modifier(): void
    {
        $item = $this->card(['title' => 'Compact card', 'estimate_hours' => 5]);
        $item->load(['participants', 'projectRef', 'assignedBy'])->loadCount('comments');

        $html = view('partials.work-card', ['c' => $item, 'compact' => true])->render();

        $this->assertStringContainsString('wc--sm', $html);
        $this->assertStringNotContainsString('5h', $html);
    }
}
