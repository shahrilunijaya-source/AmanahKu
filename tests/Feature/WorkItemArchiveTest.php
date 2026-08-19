<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class WorkItemArchiveTest extends TestCase
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

    public function test_only_a_done_card_can_be_archived(): void
    {
        $item = $this->card(['status' => 'todo']);

        $this->actingInTenant()->postJson("/app/board/{$item->id}/archive")->assertStatus(422);

        $this->assertNull($item->fresh()->archived_at);
    }

    public function test_archiving_a_done_card_sets_archived_at(): void
    {
        $item = $this->card(['status' => 'done']);

        $this->actingInTenant()->postJson("/app/board/{$item->id}/archive")->assertOk(['ok' => true]);

        $this->assertNotNull($item->fresh()->archived_at);
    }

    public function test_archived_card_is_excluded_from_the_board_but_visible_in_the_archived_list(): void
    {
        $item = $this->card(['status' => 'done', 'title' => 'Unique archived title']);
        $this->actingInTenant()->postJson("/app/board/{$item->id}/archive")->assertOk();

        $board = $this->actingInTenant()->get('/app/board')->assertOk();
        $board->assertDontSee($item->title);

        $archived = $this->actingInTenant()->getJson('/app/board/archived')->assertOk();
        $this->assertSame($item->id, $archived->json('items.0.id'));
    }

    public function test_restoring_an_archived_card_puts_it_back_at_todo(): void
    {
        $item = $this->card(['status' => 'done']);
        $item->update(['archived_at' => now()]);

        $this->actingInTenant()->postJson("/app/board/{$item->id}/restore")->assertOk();

        $item->refresh();
        $this->assertNull($item->archived_at);
        $this->assertSame('todo', $item->status);
    }

    public function test_moving_a_card_to_done_stamps_done_at(): void
    {
        $item = $this->card(['status' => 'todo']);

        $this->actingInTenant()->postJson("/app/board/{$item->id}/move", ['status' => 'done'])->assertOk();

        $this->assertNotNull($item->fresh()->done_at);
    }

    public function test_reordering_within_done_does_not_reset_done_at(): void
    {
        $stamp = now()->subHours(20);
        $item = $this->card(['status' => 'done', 'done_at' => $stamp]);

        $this->actingInTenant()->postJson("/app/board/{$item->id}/move", [
            'status' => 'done', 'ids' => [$item->id],
        ])->assertOk();

        $this->assertEqualsWithDelta($stamp->timestamp, $item->fresh()->done_at->timestamp, 1);
    }

    public function test_scheduled_command_archives_cards_done_over_a_day(): void
    {
        $old = $this->card(['status' => 'done', 'done_at' => now()->subDays(2)]);
        $recent = $this->card(['status' => 'done', 'done_at' => now()->subHours(2)]);
        $inProgress = $this->card(['status' => 'prog']);

        $this->artisan('work:archive-done')->assertSuccessful();

        $this->assertNotNull($old->fresh()->archived_at);
        $this->assertNull($recent->fresh()->archived_at);
        $this->assertNull($inProgress->fresh()->archived_at);
    }
}
