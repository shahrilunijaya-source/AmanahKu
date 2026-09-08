<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\TotAction;
use App\Models\TotSession;
use App\Models\WorkItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Acceptance\AlwaysChecks;
use Tests\TestCase;

/**
 * S12 / CR-10: the behaviour CR10Test (frozen) does not exercise directly — cross-session
 * 404 on the update/delete routes, plain-staff governance on both, helpers syncing to
 * `tot_action_helper` and then to `work_item_participant`, the `tot` label and the "TOT
 * <Month Year>" link, `TotAction::statusLabel()`, and the "Tindakan bulan lepas" block
 * across a December→January year rollover.
 */
class TotActionsTest extends TestCase
{
    use AlwaysChecks;
    use RefreshDatabase;

    private function totSession(array $attrs = []): TotSession
    {
        return TotSession::create(array_merge([
            'tenant_id' => $this->tenant()->id, 'year' => 2026, 'month' => 8, 'status' => 'done',
        ], $attrs));
    }

    private function totAction(TotSession $session, array $attrs = []): TotAction
    {
        return TotAction::create(array_merge([
            'tenant_id' => $this->tenant()->id, 'session_id' => $session->id, 'position' => 1,
            'action' => 'Tindakan',
        ], $attrs));
    }

    public function test_update_and_delete_404_on_a_tindakan_from_another_session(): void
    {
        $hr = $this->person('Hidayah', 'hr');
        $rubmin = $this->person('Rubmin');
        $sessionA = $this->totSession(['month' => 8]);
        $sessionB = $this->totSession(['month' => 9]);
        $action = $this->totAction($sessionA, ['owner_employee_id' => $rubmin->id]);

        $this->actingInTenantAs($hr)
            ->postJson("/app/tot/{$sessionB->id}/actions/{$action->id}", ['action' => 'wrong door'])
            ->assertStatus(404);
        $this->actingInTenantAs($hr)
            ->postJson("/app/tot/{$sessionB->id}/actions/{$action->id}/delete")
            ->assertStatus(404);
        $this->assertSame('Tindakan', $action->fresh()->action);
    }

    public function test_plain_staff_cannot_update_or_delete(): void
    {
        $staff = $this->person('Shazwan');
        $rubmin = $this->person('Rubmin');
        $session = $this->totSession();
        $action = $this->totAction($session, ['owner_employee_id' => $rubmin->id]);

        $this->actingInTenantAs($staff)
            ->postJson("/app/tot/{$session->id}/actions/{$action->id}", ['action' => 'rogue'])
            ->assertStatus(403);
        $this->actingInTenantAs($staff)
            ->postJson("/app/tot/{$session->id}/actions/{$action->id}/delete")
            ->assertStatus(403);
        $this->assertSame('Tindakan', $action->fresh()->action);
        $this->assertSame(1, DB::table('tot_actions')->where('session_id', $session->id)->count());
    }

    public function test_the_session_chair_may_update_and_delete_without_a_management_role(): void
    {
        $chair = $this->person('Kussairi', 'manager');
        $rubmin = $this->person('Rubmin');
        $session = $this->totSession(['chair_employee_id' => $chair->id]);
        $action = $this->totAction($session, ['owner_employee_id' => $rubmin->id]);

        $this->actingInTenantAs($chair)
            ->postJson("/app/tot/{$session->id}/actions/{$action->id}", ['action' => 'Edited by chair', 'owners' => [$rubmin->id]])
            ->assertSuccessful();
        $this->assertSame('Edited by chair', $action->fresh()->action);

        $this->actingInTenantAs($chair)
            ->postJson("/app/tot/{$session->id}/actions/{$action->id}/delete")
            ->assertSuccessful();
        $this->assertSame(0, DB::table('tot_actions')->where('id', $action->id)->count());
    }

    public function test_owners_first_id_is_the_pemilik_and_the_rest_land_in_tot_action_helper(): void
    {
        $hr = $this->person('Hidayah', 'hr');
        $rubmin = $this->person('Rubmin');
        $nabil = $this->person('Nabil');
        $syafiq = $this->person('Syafiq');
        $session = $this->totSession();

        $actionId = $this->actingInTenantAs($hr)
            ->postJson("/app/tot/{$session->id}/actions", ['action' => 'Multi-helper', 'owners' => [$rubmin->id, $nabil->id, $syafiq->id]])
            ->assertSuccessful()
            ->json('id');

        $row = DB::table('tot_actions')->where('id', $actionId)->first();
        $this->assertSame($rubmin->id, $row->owner_employee_id);
        $this->assertEqualsCanonicalizing(
            [$nabil->id, $syafiq->id],
            DB::table('tot_action_helper')->where('action_id', $actionId)->pluck('employee_id')->all()
        );
    }

    public function test_omitting_create_card_saves_the_row_only_even_with_helpers(): void
    {
        $hr = $this->person('Hidayah', 'hr');
        $rubmin = $this->person('Rubmin');
        $nabil = $this->person('Nabil');
        $session = $this->totSession();
        $cardsBefore = WorkItem::query()->count();

        $this->actingInTenantAs($hr)
            ->postJson("/app/tot/{$session->id}/actions", ['action' => 'No card yet', 'owners' => [$rubmin->id, $nabil->id]])
            ->assertSuccessful();

        $this->assertSame($cardsBefore, WorkItem::query()->count());
    }

    public function test_helpers_added_after_the_card_exists_resync_to_the_cards_participants(): void
    {
        $hr = $this->person('Hidayah', 'hr');
        $rubmin = $this->person('Rubmin');
        $nabil = $this->person('Nabil');
        $syafiq = $this->person('Syafiq');
        $session = $this->totSession();

        $response = $this->actingInTenantAs($hr)
            ->postJson("/app/tot/{$session->id}/actions", ['action' => 'Owner then helpers', 'owners' => [$rubmin->id], 'create_card' => 1])
            ->assertStatus(201);
        $actionId = $response->json('id');
        $cardId = $response->json('work_item.id');
        $this->assertSame(0, DB::table('work_item_participant')->where('work_item_id', $cardId)->count());

        $this->actingInTenantAs($hr)
            ->postJson("/app/tot/{$session->id}/actions/{$actionId}", ['action' => 'Owner then helpers', 'owners' => [$rubmin->id, $nabil->id, $syafiq->id]])
            ->assertSuccessful();

        $this->assertEqualsCanonicalizing(
            [$nabil->id, $syafiq->id],
            DB::table('work_item_participant')->where('work_item_id', $cardId)->pluck('employee_id')->all()
        );
        $this->assertTrue(
            DB::table('work_item_participant')->where('work_item_id', $cardId)->pluck('role')->every(fn ($role) => $role === 'helper')
        );

        // Dropping back to no helpers clears the card's participants too.
        $this->actingInTenantAs($hr)
            ->postJson("/app/tot/{$session->id}/actions/{$actionId}", ['action' => 'Owner then helpers', 'owners' => [$rubmin->id]])
            ->assertSuccessful();
        $this->assertSame(0, DB::table('work_item_participant')->where('work_item_id', $cardId)->count());
    }

    public function test_the_card_carries_the_tot_label_and_a_link_naming_the_slot(): void
    {
        $hr = $this->person('Hidayah', 'hr');
        $rubmin = $this->person('Rubmin');
        $session = $this->totSession(['year' => 2026, 'month' => 8]);
        $slot = DB::table('tot_slots')->insertGetId([
            'tenant_id' => $this->tenant()->id, 'session_id' => $session->id, 'position' => 1,
            'title' => 'PostgREST demo', 'kind' => 'pembentangan', 'presenter_mode' => 'solo',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingInTenantAs($hr)
            ->postJson("/app/tot/{$session->id}/actions", ['action' => 'Slotted tindakan', 'owners' => [$rubmin->id], 'slot_id' => $slot, 'create_card' => 1])
            ->assertStatus(201);

        $card = WorkItem::query()->findOrFail($response->json('work_item.id'));
        $this->assertSame(['tot'], $card->labels);
        $links = collect($card->links);
        $this->assertCount(1, $links);
        $this->assertSame('TOT August 2026 · PostgREST demo', $links->first()['label']);
        $this->assertStringEndsWith('/app/tot?year=2026&month=8', $links->first()['url']);
    }

    public function test_status_label_reads_live_from_the_linked_card(): void
    {
        $hr = $this->person('Hidayah', 'hr');
        $rubmin = $this->person('Rubmin');
        $session = $this->totSession();

        $noCard = $this->totAction($session, ['action' => 'No card', 'owner_employee_id' => $rubmin->id]);
        $this->assertSame('Open', $noCard->statusLabel());

        $todo = WorkItem::create(['tenant_id' => $this->tenant()->id, 'employee_id' => $rubmin->id, 'title' => 'x', 'type' => 'task', 'status' => 'todo', 'priority' => 'medium', 'progress' => 0]);
        $withTodo = $this->totAction($session, ['action' => 'Todo card', 'owner_employee_id' => $rubmin->id, 'work_item_id' => $todo->id]);
        $this->assertSame('Open', $withTodo->statusLabel());

        $todo->update(['status' => 'prog']);
        $this->assertSame('In Progress', $withTodo->fresh()->statusLabel());

        $todo->update(['status' => 'review']);
        $this->assertSame('In Progress', $withTodo->fresh()->statusLabel());

        $todo->update(['status' => 'done']);
        $this->assertSame('Done', $withTodo->fresh()->statusLabel());
    }

    public function test_deleting_a_tindakan_with_no_card_yet_just_removes_the_row(): void
    {
        $hr = $this->person('Hidayah', 'hr');
        $rubmin = $this->person('Rubmin');
        $session = $this->totSession();
        $action = $this->totAction($session, ['owner_employee_id' => $rubmin->id]);

        $this->actingInTenantAs($hr)
            ->postJson("/app/tot/{$session->id}/actions/{$action->id}/delete")
            ->assertSuccessful();

        $this->assertSame(0, DB::table('tot_actions')->where('id', $action->id)->count());
        $this->assertTrue(AuditLog::query()->where('action', 'Deleted TOT tindakan')->exists());
    }

    public function test_last_month_block_crosses_a_december_to_january_year_boundary(): void
    {
        $staff = $this->person('Shazwan');
        $rubmin = $this->person('Rubmin');
        $december = $this->totSession(['year' => 2025, 'month' => 12]);
        $this->totAction($december, ['action' => 'Bawa masuk tahun baharu', 'owner_employee_id' => $rubmin->id]);
        $january = $this->totSession(['year' => 2026, 'month' => 1]);

        $page = $this->actingInTenantAs($staff)->get('/app/tot?year=2026');
        $page->assertOk();
        $page->assertSee('Tindakan bulan lepas');
        $page->assertSeeInOrder(['Tindakan bulan lepas', 'Bawa masuk tahun baharu', 'Rubmin', 'Open']);
        $this->assertSame($december->id, $january->previousSession()->id);
    }

    public function test_always_checks(): void
    {
        $this->assertDueDateLocked();
        $this->assertAuditLogImmutable();
        $this->assertDashboardUnchanged();
        $this->assertKeepItPlainHonoured();
    }
}
