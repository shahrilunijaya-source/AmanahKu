<?php

namespace Tests\Acceptance;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\TotSession;
use App\Models\WorkItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Acceptance for docs/specs/CR-10.md (session S12, Tindakan Susulan to T.A.A. with the next
 * TOT date). Shapes fixed in OPEN "QA / CR-10 / shapes fixed by CR10Test": `owners[]` on
 * `tot.actions.store` (first = Pemilik, rest = helpers in `tot_action_helper`), `create_card`
 * makes the card on save; `tot.actions.update` and `tot.actions.delete`; the card carries the
 * `tot` label, a "TOT <Month YYYY>" link back to the session and helpers as tagged; the row
 * status reads live from the card (Open / In Progress / Done); the next session's drawer shows
 * "Tindakan bulan lepas".
 */
class CR10Test extends TestCase
{
    use AlwaysChecks;
    use RefreshDatabase;

    private Employee $hr;

    private Employee $chair;

    private Employee $rubmin;

    private Employee $nabil;

    private Employee $syafiq;

    private Employee $staff;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-03 10:00:00');

        $this->hr = $this->person('Hidayah', 'hr');
        $this->chair = $this->person('Kussairi', 'manager');
        $this->rubmin = $this->person('Rubmin');
        $this->nabil = $this->person('Nabil');
        $this->syafiq = $this->person('Syafiq');
        $this->staff = $this->person('Shazwan');
    }

    // ── 1. add Tindakan 1 with owner + helper, card lands on Rubmin's board ──

    public function test_acceptance_1_saving_tindakan_1_makes_a_card_for_rubmin_with_nabil_tagged_due_on_the_september_tot(): void
    {
        $session = $this->augustSession();
        $cardsBefore = WorkItem::query()->count();

        // Plain staff cannot add a tindakan at all.
        $this->actingInTenantAs($this->staff)
            ->postJson("/app/tot/{$session->id}/actions", ['action' => 'Rogue', 'owners' => [$this->staff->id], 'create_card' => 1])
            ->assertStatus(403);
        $this->assertSame($cardsBefore, WorkItem::query()->count());

        $response = $this->actingInTenantAs($this->hr)
            ->postJson("/app/tot/{$session->id}/actions", [
                'action' => 'Sediakan PoC PostgREST untuk projek RMS',
                'owners' => [$this->rubmin->id, $this->nabil->id],
                'create_card' => 1,
            ]);
        $response->assertStatus(201);
        $actionId = $response->json('id');
        $cardId = $response->json('work_item.id');
        $this->assertNotNull($actionId);
        $this->assertNotNull($cardId);
        $this->assertSame('2026-09-05', $response->json('work_item.due_at'));

        $row = DB::table('tot_actions')->where('id', $actionId)->first();
        $this->assertSame($this->rubmin->id, $row->owner_employee_id, 'the first Pemilik is the owner');
        $this->assertSame($cardId, $row->work_item_id);
        $this->assertNull($row->target_date, 'no date given means the next TOT Saturday, not a stored guess');
        $this->assertSame([$this->nabil->id], DB::table('tot_action_helper')->where('action_id', $actionId)->pluck('employee_id')->all());

        $card = WorkItem::query()->findOrFail($cardId);
        $this->assertSame($this->rubmin->id, $card->employee_id);
        $this->assertSame('Sediakan PoC PostgREST untuk projek RMS', $card->title);
        $this->assertSame('task', $card->type);
        $this->assertSame('todo', $card->status);
        $this->assertSame('2026-09-05', $card->due_at?->format('Y-m-d'), 'due = the September TOT Saturday');
        $this->assertSame('Bulan hadapan', $card->due_label);
        $this->assertContains('tot', $card->labels ?? [], 'the TOT Action label');
        $this->assertArrayHasKey('tot', WorkItem::LABELS);
        $this->assertSame('TOT Action', WorkItem::LABELS['tot'][0]);
        $links = collect($card->links ?? []);
        $this->assertTrue($links->contains(fn ($l) => str_starts_with((string) ($l['label'] ?? ''), 'TOT') && str_contains((string) ($l['url'] ?? ''), '/app/tot?year=2026')), 'a TOT <month> link back to the session');
        $this->assertSame(
            [['employee_id' => $this->nabil->id, 'role' => 'helper']],
            DB::table('work_item_participant')->where('work_item_id', $cardId)->get()->map(fn ($p) => ['employee_id' => $p->employee_id, 'role' => $p->role])->all(),
            'Nabil is tagged as helper'
        );

        // Rubmin sees it on his board; Nabil sees it tagged.
        $this->actingInTenantAs($this->rubmin)->get('/app/board')
            ->assertOk()
            ->assertSee('Sediakan PoC PostgREST untuk projek RMS');
        $this->actingInTenantAs($this->nabil)->get('/app/board')
            ->assertOk()
            ->assertSee('Sediakan PoC PostgREST untuk projek RMS')
            ->assertSee('Tagged – Helper');

        // The session page shows the row with owner, helper and its status.
        $page = $this->actingInTenantAs($this->staff)->get('/app/tot?year=2026');
        $page->assertOk();
        $page->assertSee('Sediakan PoC PostgREST untuk projek RMS');
        $page->assertSeeInOrder(['Sediakan PoC PostgREST untuk projek RMS', 'Rubmin', 'Nabil', 'Open']);

        // The chair may add one too, even as a manager.
        $this->actingInTenantAs($this->chair)
            ->postJson("/app/tot/{$session->id}/actions", ['action' => 'Chair adds one', 'owners' => [$this->syafiq->id], 'create_card' => 1])
            ->assertStatus(201);
        $this->assertSame($cardsBefore + 2, WorkItem::query()->count());

        // No Event was created, and the save is audited.
        $this->assertSame(0, DB::table('company_events')->count());
        $this->assertTrue(AuditLog::query()->where('target', 'like', '%Sediakan PoC PostgREST%')->exists(), 'no audit row for the tindakan save');
    }

    // ── 2. Sasaran editable until the card exists, then locked with the due date ──

    public function test_acceptance_2_sasaran_changes_before_save_and_locks_with_the_task_due_date_after(): void
    {
        $session = $this->augustSession();

        $actionId = $this->actingInTenantAs($this->hr)
            ->postJson("/app/tot/{$session->id}/actions", [
                'action' => 'Guardrail berpusat untuk semua projek',
                'owners' => [$this->syafiq->id],
                'target_date' => '2026-08-20',
                'create_card' => 0,
            ])
            ->assertSuccessful()
            ->json('id');
        $this->assertNull(DB::table('tot_actions')->where('id', $actionId)->value('work_item_id'), 'create_card off saves the row only');

        // Before the card exists Sasaran moves freely.
        $this->actingInTenantAs($this->hr)
            ->postJson("/app/tot/{$session->id}/actions/{$actionId}", ['action' => 'Guardrail berpusat untuk semua projek', 'owners' => [$this->syafiq->id], 'target_date' => '2026-08-27'])
            ->assertSuccessful();
        $this->assertSame('2026-08-27', Carbon::parse(DB::table('tot_actions')->where('id', $actionId)->value('target_date'))->format('Y-m-d'));
        $this->actingInTenantAs($this->staff)->get('/app/tot?year=2026')->assertOk()->assertSee('27 Aug 2026');

        // Plain staff cannot edit.
        $this->actingInTenantAs($this->staff)
            ->postJson("/app/tot/{$session->id}/actions/{$actionId}", ['action' => 'Rogue', 'owners' => [$this->syafiq->id], 'target_date' => '2026-08-28'])
            ->assertStatus(403);

        // The card is created from the row: it takes the Sasaran as its due date.
        $cardId = $this->actingInTenantAs($this->hr)
            ->postJson("/app/tot/{$session->id}/actions/{$actionId}/card")
            ->assertStatus(201)
            ->json('work_item.id');
        $card = WorkItem::query()->findOrFail($cardId);
        $this->assertSame('2026-08-27', $card->due_at?->format('Y-m-d'));
        $this->assertNotSame('Bulan hadapan', (string) $card->due_label);

        // After the card exists, Sasaran is locked.
        $this->actingInTenantAs($this->hr)
            ->postJson("/app/tot/{$session->id}/actions/{$actionId}", ['action' => 'Guardrail berpusat untuk semua projek', 'owners' => [$this->syafiq->id], 'target_date' => '2026-09-10'])
            ->assertStatus(422);
        $this->assertSame('2026-08-27', Carbon::parse(DB::table('tot_actions')->where('id', $actionId)->value('target_date'))->format('Y-m-d'));
        $this->assertSame('2026-08-27', $card->fresh()->due_at?->format('Y-m-d'));

        // And the Task due date is locked on the board too (dates contract Rule 4).
        $this->actingInTenantAs($this->syafiq)
            ->patchJson("/app/board/{$cardId}", ['due_at' => '2026-09-10'])
            ->assertStatus(422);
        $this->assertSame('2026-08-27', $card->fresh()->due_at?->format('Y-m-d'));

        // The text may still change, and the card title follows.
        $this->actingInTenantAs($this->hr)
            ->postJson("/app/tot/{$session->id}/actions/{$actionId}", ['action' => 'Guardrail berpusat untuk semua projek (v2)', 'owners' => [$this->syafiq->id], 'target_date' => '2026-08-27'])
            ->assertSuccessful();
        $this->assertSame('Guardrail berpusat untuk semua projek (v2)', $card->fresh()->title);

        // Helpers can be added later and land as tagged.
        $this->actingInTenantAs($this->hr)
            ->postJson("/app/tot/{$session->id}/actions/{$actionId}", ['action' => 'Guardrail berpusat untuk semua projek (v2)', 'owners' => [$this->syafiq->id, $this->nabil->id], 'target_date' => '2026-08-27'])
            ->assertSuccessful();
        $this->assertSame(1, DB::table('work_item_participant')->where('work_item_id', $cardId)->where('employee_id', $this->nabil->id)->where('role', 'helper')->count());

        // Changing the Pemilik after the card exists is refused (the board owns reassignment).
        $this->actingInTenantAs($this->hr)
            ->postJson("/app/tot/{$session->id}/actions/{$actionId}", ['action' => 'Guardrail berpusat untuk semua projek (v2)', 'owners' => [$this->rubmin->id], 'target_date' => '2026-08-27'])
            ->assertStatus(422);
        $this->assertSame($this->syafiq->id, $card->fresh()->employee_id);
    }

    // ── 3. card status flows back to the row ────────────────────────

    public function test_acceptance_3_rubmin_moving_the_card_to_done_shows_done_on_the_tindakan_row(): void
    {
        $session = $this->augustSession();
        $response = $this->actingInTenantAs($this->hr)
            ->postJson("/app/tot/{$session->id}/actions", ['action' => 'Sediakan PoC PostgREST untuk projek RMS', 'owners' => [$this->rubmin->id], 'create_card' => 1])
            ->assertStatus(201);
        $cardId = $response->json('work_item.id');

        $this->actingInTenantAs($this->staff)->get('/app/tot?year=2026')
            ->assertOk()
            ->assertSeeInOrder(['Sediakan PoC PostgREST untuk projek RMS', 'Open']);

        $this->actingInTenantAs($this->rubmin)
            ->postJson("/app/board/{$cardId}/move", ['status' => 'prog'])
            ->assertSuccessful();
        $this->actingInTenantAs($this->staff)->get('/app/tot?year=2026')
            ->assertOk()
            ->assertSeeInOrder(['Sediakan PoC PostgREST untuk projek RMS', 'In Progress']);

        $this->actingInTenantAs($this->rubmin)
            ->postJson("/app/board/{$cardId}/move", ['status' => 'done'])
            ->assertSuccessful();
        $this->assertSame('done', WorkItem::query()->find($cardId)->status);
        $this->actingInTenantAs($this->staff)->get('/app/tot?year=2026')
            ->assertOk()
            ->assertSeeInOrder(['Sediakan PoC PostgREST untuk projek RMS', 'Done']);
    }

    // ── 4. September opens with last month's actions and status ────

    public function test_acceptance_4_september_session_opens_with_last_months_four_actions_and_their_status(): void
    {
        $august = $this->augustSession();
        $texts = [
            'Sediakan PoC PostgREST untuk projek RMS',
            'Guardrail berpusat untuk semua projek',
            'Uji Graphify pada repo Amanahku',
            'Sambung demo chatbox bulan depan',
        ];
        $owners = [$this->rubmin, $this->syafiq, $this->staff, $this->syafiq];
        $cards = [];
        foreach ($texts as $i => $text) {
            $cards[] = $this->actingInTenantAs($this->hr)
                ->postJson("/app/tot/{$august->id}/actions", ['action' => $text, 'owners' => [$owners[$i]->id], 'create_card' => 1])
                ->assertStatus(201)
                ->json('work_item.id');
        }
        $this->actingInTenantAs($this->rubmin)->postJson("/app/board/{$cards[0]}/move", ['status' => 'done'])->assertSuccessful();
        $this->actingInTenantAs($this->syafiq)->postJson("/app/board/{$cards[1]}/move", ['status' => 'prog'])->assertSuccessful();

        $this->actingInTenantAs($this->hr)
            ->postJson('/app/tot', ['year' => 2026, 'month' => 9, 'status' => 'planned', 'presenter_employee_ids' => [$this->syafiq->id]])
            ->assertSuccessful();

        Carbon::setTestNow('2026-09-05 09:00:00');
        $page = $this->actingInTenantAs($this->staff)->get('/app/tot?year=2026');
        $page->assertOk();
        $page->assertSee('Tindakan bulan lepas');
        $page->assertSeeInOrder([
            'Tindakan bulan lepas',
            'Sediakan PoC PostgREST untuk projek RMS', 'Done',
            'Guardrail berpusat untuk semua projek', 'In Progress',
            'Uji Graphify pada repo Amanahku', 'Open',
            'Sambung demo chatbox bulan depan', 'Open',
        ]);
        $this->assertSame(4, DB::table('tot_actions')->where('session_id', $august->id)->count(), 'nothing was copied into September');
        $september = TotSession::query()->where('year', 2026)->where('month', 9)->firstOrFail();
        $this->assertSame(0, DB::table('tot_actions')->where('session_id', $september->id)->count());

        // A session with no previous-month actions shows no block: August itself, since July has none.
        $this->actingInTenantAs($this->staff)->get('/app/tot?year=2026')
            ->assertOk()
            ->assertSee('Tindakan bulan lepas');
        DB::table('tot_actions')->where('session_id', $august->id)->delete();
        $this->actingInTenantAs($this->staff)->get('/app/tot?year=2026')
            ->assertOk()
            ->assertDontSee('Tindakan bulan lepas');
    }

    // ── scope 5. deleting a tindakan archives its card ──────────────

    public function test_scope_5_deleting_a_tindakan_archives_its_card_and_leaves_the_board(): void
    {
        $session = $this->augustSession();
        $response = $this->actingInTenantAs($this->hr)
            ->postJson("/app/tot/{$session->id}/actions", ['action' => 'Tindakan yang dibatalkan', 'owners' => [$this->rubmin->id], 'create_card' => 1])
            ->assertStatus(201);
        $actionId = $response->json('id');
        $cardId = $response->json('work_item.id');

        $this->actingInTenantAs($this->staff)
            ->postJson("/app/tot/{$session->id}/actions/{$actionId}/delete")
            ->assertStatus(403);

        $this->actingInTenantAs($this->hr)
            ->postJson("/app/tot/{$session->id}/actions/{$actionId}/delete")
            ->assertSuccessful();

        $this->assertSame(0, DB::table('tot_actions')->where('id', $actionId)->count());
        $card = WorkItem::query()->find($cardId);
        $this->assertNotNull($card, 'the card is archived, never deleted');
        $this->assertNotNull($card->archived_at);
        $this->actingInTenantAs($this->rubmin)->get('/app/board')
            ->assertOk()
            ->assertDontSee('Tindakan yang dibatalkan');
        $this->assertTrue(AuditLog::query()->where('target', 'like', '%Tindakan yang dibatalkan%')->exists());
    }

    // ── every-session checks ────────────────────────────────────────

    public function test_always_checks(): void
    {
        $this->assertDueDateLocked();
        $this->assertAuditLogImmutable();
        $this->assertDashboardUnchanged();
        $this->assertKeepItPlainHonoured();
    }

    // ── helpers ──────────────────────────────────────────────────────

    private function augustSession(): TotSession
    {
        $id = $this->actingInTenantAs($this->hr)
            ->postJson('/app/tot', ['year' => 2026, 'month' => 8, 'status' => 'done', 'presenter_employee_ids' => [$this->rubmin->id]])
            ->assertSuccessful()
            ->json('id');

        $this->actingInTenantAs($this->hr)
            ->post("/app/tot/{$id}", ['year' => 2026, 'month' => 8, 'status' => 'done', 'chair_employee_id' => $this->chair->id])
            ->assertSessionHasNoErrors();

        return TotSession::query()->findOrFail($id);
    }
}
