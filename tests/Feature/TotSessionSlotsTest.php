<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\TotAction;
use App\Models\TotReaction;
use App\Models\TotSession;
use App\Models\TotSlot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Acceptance\AlwaysChecks;
use Tests\TestCase;

/**
 * S11 / CR-09: slot update/delete/reorder, per-slot reactions, the legacy backfill, and
 * cross-session 404s. CR09Test (frozen) covers the four acceptance items; this file covers
 * the CR-09 behaviour it does not exercise directly.
 */
class TotSessionSlotsTest extends TestCase
{
    use AlwaysChecks;
    use RefreshDatabase;

    private function totSession(array $attrs = []): TotSession
    {
        return TotSession::create(array_merge([
            'tenant_id' => $this->tenant()->id, 'year' => 2026, 'month' => 8, 'status' => 'planned',
        ], $attrs));
    }

    public function test_updating_a_slot_changes_only_the_fields_the_request_carried(): void
    {
        $hr = $this->person('Hidayah', 'hr');
        $session = $this->totSession();
        $slot = TotSlot::create([
            'tenant_id' => $this->tenant()->id, 'session_id' => $session->id, 'position' => 1,
            'title' => 'Original', 'kind' => 'pembentangan', 'format' => 'slide', 'status' => 'rasmi', 'summary' => 'Old summary',
        ]);

        // A position-only reorder must not wipe title/format/status/summary.
        $this->actingInTenantAs($hr)
            ->postJson("/app/tot/{$session->id}/slots/{$slot->id}", ['position' => 3])
            ->assertSuccessful();

        $slot->refresh();
        $this->assertSame(3, $slot->position);
        $this->assertSame('Original', $slot->title);
        $this->assertSame('slide', $slot->format);
        $this->assertSame('rasmi', $slot->status);
        $this->assertSame('Old summary', $slot->summary);

        // A full edit changes the fields it does carry.
        $this->actingInTenantAs($hr)
            ->postJson("/app/tot/{$session->id}/slots/{$slot->id}", [
                'title' => 'Renamed', 'kind' => 'demonstrasi', 'format' => 'demo', 'status' => 'ujian', 'summary' => 'New summary',
            ])
            ->assertSuccessful();

        $slot->refresh();
        $this->assertSame('Renamed', $slot->title);
        $this->assertSame('demonstrasi', $slot->kind);
        $this->assertSame('demo', $slot->format);
        $this->assertSame('ujian', $slot->status);
        $this->assertSame('New summary', $slot->summary);

        // Plain staff cannot edit a slot.
        $staff = $this->person('Shazwan');
        $this->actingInTenantAs($staff)
            ->postJson("/app/tot/{$session->id}/slots/{$slot->id}", ['title' => 'Rogue'])
            ->assertStatus(403);
    }

    public function test_reordering_slots_keeps_the_positions_the_request_set(): void
    {
        $hr = $this->person('Hidayah', 'hr');
        $session = $this->totSession();
        $a = TotSlot::create(['tenant_id' => $this->tenant()->id, 'session_id' => $session->id, 'position' => 1, 'title' => 'A', 'kind' => 'pembentangan']);
        $b = TotSlot::create(['tenant_id' => $this->tenant()->id, 'session_id' => $session->id, 'position' => 2, 'title' => 'B', 'kind' => 'pembentangan']);

        $this->actingInTenantAs($hr)->postJson("/app/tot/{$session->id}/slots/{$a->id}", ['position' => 2])->assertSuccessful();
        $this->actingInTenantAs($hr)->postJson("/app/tot/{$session->id}/slots/{$b->id}", ['position' => 1])->assertSuccessful();

        $this->assertSame(
            ['B', 'A'],
            DB::table('tot_slots')->where('session_id', $session->id)->orderBy('position')->pluck('title')->all()
        );
    }

    public function test_deleting_a_slot_removes_it_and_is_gated_to_management(): void
    {
        $hr = $this->person('Hidayah', 'hr');
        $staff = $this->person('Shazwan');
        $session = $this->totSession();
        $slot = TotSlot::create(['tenant_id' => $this->tenant()->id, 'session_id' => $session->id, 'position' => 1, 'title' => 'Gone soon', 'kind' => 'pembentangan']);

        $this->actingInTenantAs($staff)
            ->postJson("/app/tot/{$session->id}/slots/{$slot->id}/delete")
            ->assertStatus(403);
        $this->assertDatabaseHas('tot_slots', ['id' => $slot->id]);

        $this->actingInTenantAs($hr)
            ->postJson("/app/tot/{$session->id}/slots/{$slot->id}/delete")
            ->assertSuccessful();
        $this->assertDatabaseMissing('tot_slots', ['id' => $slot->id]);
    }

    public function test_a_slot_reaction_toggles_independently_of_the_session_reaction(): void
    {
        $staff = $this->person('Shazwan');
        $session = $this->totSession();
        $slot = TotSlot::create(['tenant_id' => $this->tenant()->id, 'session_id' => $session->id, 'position' => 1, 'title' => 'Slot', 'kind' => 'pembentangan']);

        // Same person, at the session level and the slot level, with two different emoji:
        // a same-emoji cross-context collision is a documented, out-of-scope gap (see
        // OPEN.md "S11 / CR-09 / slot-thread reaction collides with ..."), not this test.
        $this->actingInTenantAs($staff)->postJson("/app/tot/{$session->id}/react", ['reaction' => 'power'])->assertSuccessful();
        $this->actingInTenantAs($staff)->postJson("/app/tot/{$session->id}/slots/{$slot->id}/react", ['emoji' => 'legend'])->assertSuccessful();

        $this->assertSame(1, TotReaction::where('session_id', $session->id)->whereNull('slot_id')->count());
        $this->assertSame(1, TotReaction::where('slot_id', $slot->id)->count());

        // Pressing the same emoji again on the slot is the undo.
        $this->actingInTenantAs($staff)->postJson("/app/tot/{$session->id}/slots/{$slot->id}/react", ['emoji' => 'legend'])->assertSuccessful();
        $this->assertSame(0, TotReaction::where('slot_id', $slot->id)->count());
        $this->assertSame(1, TotReaction::where('session_id', $session->id)->whereNull('slot_id')->count(), 'the session-level reaction is untouched by the slot toggle');
    }

    public function test_a_slot_from_another_session_404s_on_every_slot_route(): void
    {
        $hr = $this->person('Hidayah', 'hr');
        $sessionA = $this->totSession(['month' => 8]);
        $sessionB = $this->totSession(['month' => 9]);
        $slot = TotSlot::create(['tenant_id' => $this->tenant()->id, 'session_id' => $sessionA->id, 'position' => 1, 'title' => 'A only', 'kind' => 'pembentangan']);

        $this->actingInTenantAs($hr)->postJson("/app/tot/{$sessionB->id}/slots/{$slot->id}", ['title' => 'x'])->assertStatus(404);
        $this->actingInTenantAs($hr)->postJson("/app/tot/{$sessionB->id}/slots/{$slot->id}/delete")->assertStatus(404);
        $this->actingInTenantAs($hr)->postJson("/app/tot/{$sessionB->id}/slots/{$slot->id}/comment", ['body' => 'x'])->assertStatus(404);
        $this->actingInTenantAs($hr)->getJson("/app/tot/{$sessionB->id}/slots/{$slot->id}/comments")->assertStatus(404);
        $this->actingInTenantAs($hr)->postJson("/app/tot/{$sessionB->id}/slots/{$slot->id}/react", ['emoji' => 'power'])->assertStatus(404);
    }

    public function test_a_tindakan_from_another_session_404s_on_the_card_route(): void
    {
        $hr = $this->person('Hidayah', 'hr');
        $owner = $this->person('Rubmin');
        $sessionA = $this->totSession(['month' => 8]);
        $sessionB = $this->totSession(['month' => 9]);
        $action = TotAction::create([
            'tenant_id' => $this->tenant()->id, 'session_id' => $sessionA->id, 'position' => 1,
            'action' => 'Belongs to A', 'owner_employee_id' => $owner->id,
        ]);

        $this->actingInTenantAs($hr)
            ->postJson("/app/tot/{$sessionB->id}/actions/{$action->id}/card")
            ->assertStatus(404);
    }

    public function test_legacy_backfill_gives_a_titled_session_with_no_slot_one_pembentangan_slot(): void
    {
        $presenter = $this->person('Legacy Presenter');
        $withTitle = $this->totSession(['month' => 1, 'title' => 'Legacy topic', 'description' => 'Legacy summary', 'presenter_employee_id' => $presenter->id]);
        $withoutTitle = $this->totSession(['month' => 2, 'title' => null]);
        $alreadySlotted = $this->totSession(['month' => 3, 'title' => 'Already has a slot']);
        TotSlot::create(['tenant_id' => $this->tenant()->id, 'session_id' => $alreadySlotted->id, 'position' => 1, 'title' => 'Existing', 'kind' => 'demonstrasi']);

        TotSlot::backfillLegacySessions();

        $slot = DB::table('tot_slots')->where('session_id', $withTitle->id)->first();
        $this->assertNotNull($slot, 'a titled session with no slot yet must be backfilled');
        $this->assertSame(1, $slot->position);
        $this->assertSame('pembentangan', $slot->kind);
        $this->assertSame('Legacy topic', $slot->title);
        $this->assertSame('Legacy summary', $slot->summary);
        $this->assertSame([$presenter->id], DB::table('tot_slot_presenter')->where('slot_id', $slot->id)->pluck('employee_id')->all());

        $this->assertSame(0, DB::table('tot_slots')->where('session_id', $withoutTitle->id)->count(), 'a session with no title gets no backfilled slot');
        $this->assertSame(1, DB::table('tot_slots')->where('session_id', $alreadySlotted->id)->count(), 'a session that already has a slot is left alone');

        // Idempotent: running it again does not add a second slot.
        TotSlot::backfillLegacySessions();
        $this->assertSame(1, DB::table('tot_slots')->where('session_id', $withTitle->id)->count());
    }

    public function test_hr_and_the_chair_can_render_the_tot_screen_with_management_forms(): void
    {
        // CR09Test only ever renders /app/tot as plain staff, so the @if ($canManageSession)
        // blocks (attendance form, slot edit/add forms, tindakan add form) never compile in
        // that suite. Render as the two other actors who see them.
        $hr = $this->person('Hidayah', 'hr');
        $chairOnly = $this->person('Chairman'); // plain employee role, chair by session field only
        $session = $this->totSession(['title' => 'Topic', 'chair_employee_id' => $chairOnly->id]);
        TotSlot::create(['tenant_id' => $this->tenant()->id, 'session_id' => $session->id, 'position' => 1, 'title' => 'Slot', 'kind' => 'pembentangan']);
        TotAction::create(['tenant_id' => $this->tenant()->id, 'session_id' => $session->id, 'position' => 1, 'action' => 'Do it', 'owner_employee_id' => $hr->id]);

        $this->actingInTenantAs($hr)->get('/app/tot?year=2026')->assertOk();
        $this->actingInTenantAs($chairOnly)->get('/app/tot?year=2026')->assertOk();
    }

    public function test_the_chair_may_update_session_fields_by_the_chair_branch_alone_not_a_role(): void
    {
        $chairOnly = $this->person('Chairman'); // no manager/hr/management role
        $session = $this->totSession(['title' => 'Topic', 'chair_employee_id' => $chairOnly->id]);

        $this->assertTrue($session->isManagedBy('employee', $chairOnly), 'the chair branch of isManagedBy() must fire for a plain employee role');

        $this->actingInTenantAs($chairOnly)
            ->post("/app/tot/{$session->id}", [
                'nota_url' => 'https://example.com/nota.pdf',
                'next_agenda' => 'Next month topic',
            ])
            ->assertSessionHasNoErrors();

        $session->refresh();
        $this->assertSame('https://example.com/nota.pdf', $session->nota_url);
        $this->assertSame('Next month topic', $session->next_agenda);
    }

    public function test_a_non_chair_non_privileged_employee_may_not_update_session_fields(): void
    {
        $chairOnly = $this->person('Chairman');
        $bystander = $this->person('Bystander');
        $session = $this->totSession(['title' => 'Topic', 'chair_employee_id' => $chairOnly->id]);

        $this->assertFalse($session->isManagedBy('employee', $bystander));

        $this->actingInTenantAs($bystander)
            ->post("/app/tot/{$session->id}", ['nota_url' => 'https://example.com/nota.pdf'])
            ->assertStatus(403);
    }

    public function test_always_checks(): void
    {
        $this->assertDueDateLocked();
        $this->assertAuditLogImmutable();
        $this->assertDashboardUnchanged();
        $this->assertKeepItPlainHonoured();
    }
}
