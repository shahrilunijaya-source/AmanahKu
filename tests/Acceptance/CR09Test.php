<?php

namespace Tests\Acceptance;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\TotSession;
use App\Models\WorkItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Acceptance for docs/specs/CR-09.md (session S11, TOT sessions: chair, attendance, ordered
 * slots with their own discussion, Tindakan with Create T.A.A. task). Shapes fixed in OPEN
 * "QA / CR-09 / shapes fixed by CR09Test": `tot_sessions` gains chair_employee_id, nota_url,
 * next_agenda; tables `tot_slots` (+ `tot_slot_presenter`), `tot_attendance`, `tot_actions`;
 * `tot_comments.slot_id` for per-slot threads; routes tot.slots.store / tot.slots.comment /
 * tot.slots.comments / tot.attendance / tot.actions.store / tot.actions.card; the year screen
 * `GET /app/tot?year=YYYY` renders it all on one page.
 *
 * The fixture is the 1 Ogos 2026 session from the source sheet: chair Kussairi, four slots,
 * 19 present + 1 absent, four tindakan, next-month agenda "Amy, then Rubmin/Syafiq".
 */
class CR09Test extends TestCase
{
    use AlwaysChecks;
    use RefreshDatabase;

    private Employee $hr;

    private Employee $chair;

    private Employee $rubmin;

    private Employee $amy;

    private Employee $syafiq;

    private Employee $staff;

    /** @var array<int, Employee> the 20 people on the August roster (chair, hr, rubmin, amy, syafiq, staff + 14 more) */
    private array $roster = [];

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-03 10:00:00');

        $this->hr = $this->person('Hidayah', 'hr');
        $this->chair = $this->person('Kussairi', 'manager');
        $this->rubmin = $this->person('Rubmin');
        $this->amy = $this->person('Amy');
        $this->syafiq = $this->person('Syafiq');
        $this->staff = $this->person('Shazwan');

        $this->roster = [$this->chair, $this->hr, $this->rubmin, $this->amy, $this->syafiq, $this->staff];
        for ($i = 1; $i <= 14; $i++) {
            $this->roster[] = $this->person('Staff '.$i);
        }
    }

    // ── 1. the August session, one page ─────────────────────────────

    public function test_acceptance_1_august_session_has_four_slots_twenty_attendance_rows_and_four_actions_on_one_page(): void
    {
        $session = $this->augustSession();

        $this->assertSame('2026-08-01', $session->session_date->format('Y-m-d'), 'the August session is the first Saturday');
        $this->assertSame($this->chair->id, $session->chair_employee_id);
        $this->assertSame('https://drive.example.com/nota-ogos-2026.pdf', $session->nota_url);

        $this->assertSame(4, DB::table('tot_slots')->where('session_id', $session->id)->count());
        $this->assertSame(
            ['Google AI Antigravity', 'PostgREST - Building REST APIs Directly from PostgreSQL', 'Graphify vs Repomix', 'Sambungan: Laravel AI chatbox'],
            DB::table('tot_slots')->where('session_id', $session->id)->orderBy('position')->pluck('title')->all(),
            'slots keep the order they were added in'
        );
        $this->assertSame(
            ['pembentangan', 'pembentangan', 'demonstrasi', 'sambungan'],
            DB::table('tot_slots')->where('session_id', $session->id)->orderBy('position')->pluck('kind')->all()
        );

        $postgrest = DB::table('tot_slots')->where('session_id', $session->id)->where('position', 2)->first();
        $this->assertSame('team', $postgrest->presenter_mode);
        $this->assertSame('ujian', $postgrest->status);
        $this->assertSame('demo', $postgrest->format);
        $this->assertEqualsCanonicalizing(
            [$this->rubmin->id, $this->syafiq->id],
            DB::table('tot_slot_presenter')->where('slot_id', $postgrest->id)->pluck('employee_id')->all()
        );
        $this->assertSame(1, (int) DB::table('tot_slot_presenter')->where('slot_id', $postgrest->id)->where('employee_id', $this->syafiq->id)->value('support'), 'Syafiq is sokongan on the PostgREST slot');

        $this->assertSame(20, DB::table('tot_attendance')->where('session_id', $session->id)->count());
        $this->assertSame(19, DB::table('tot_attendance')->where('session_id', $session->id)->where('present', true)->count());
        $absent = DB::table('tot_attendance')->where('session_id', $session->id)->where('present', false)->get();
        $this->assertCount(1, $absent);
        $this->assertSame($this->staff->id, $absent[0]->employee_id);
        $this->assertSame('Cuti sakit', $absent[0]->reason);

        $this->assertSame(4, DB::table('tot_actions')->where('session_id', $session->id)->count());
        $first = DB::table('tot_actions')->where('session_id', $session->id)->orderBy('position')->first();
        $this->assertSame('Sediakan dokumentasi PostgREST untuk projek RMS', $first->action);
        $this->assertSame($this->rubmin->id, $first->owner_employee_id);
        $this->assertNull($first->target_date, 'no date given means Bulan hadapan, not a made-up date');
        $this->assertSame($postgrest->id, $first->slot_id);

        // One page: the year screen shows chair, attendance, every slot and every action.
        $page = $this->actingInTenantAs($this->staff)->get('/app/tot?year=2026');
        $page->assertOk();
        $page->assertSee('Kussairi');
        $page->assertSee('19 hadir');
        $page->assertSee('1 tidak hadir');
        $page->assertSee('Cuti sakit');
        $page->assertSee('nota-ogos-2026.pdf');
        foreach (['Google AI Antigravity', 'PostgREST - Building REST APIs Directly from PostgreSQL', 'Graphify vs Repomix', 'Sambungan: Laravel AI chatbox'] as $title) {
            $page->assertSee($title);
        }
        $page->assertSeeInOrder(['Google AI Antigravity', 'PostgREST', 'Graphify vs Repomix', 'Sambungan: Laravel AI chatbox']);
        foreach (['Sediakan dokumentasi PostgREST untuk projek RMS', 'Kemaskini slaid Antigravity', 'Uji Graphify pada repo Amanahku', 'Sambung demo chatbox bulan depan'] as $action) {
            $page->assertSee($action);
        }
        $page->assertSee('Bulan hadapan');

        // Governance: plain staff cannot edit the session, its slots, attendance or tindakan.
        $this->actingInTenantAs($this->staff)
            ->postJson("/app/tot/{$session->id}/slots", ['title' => 'Rogue', 'kind' => 'pembentangan'])
            ->assertStatus(403);
        $this->actingInTenantAs($this->staff)
            ->postJson("/app/tot/{$session->id}/attendance", ['present' => [$this->staff->id], 'absent' => []])
            ->assertStatus(403);
        $this->actingInTenantAs($this->staff)
            ->postJson("/app/tot/{$session->id}/actions", ['action' => 'Rogue', 'owner_employee_id' => $this->staff->id])
            ->assertStatus(403);
        $this->assertSame(4, DB::table('tot_slots')->where('session_id', $session->id)->count());
        $this->assertSame(4, DB::table('tot_actions')->where('session_id', $session->id)->count());

        // The chair may, even though Kussairi is a manager, not hr.
        $this->actingInTenantAs($this->chair)
            ->postJson("/app/tot/{$session->id}/actions", ['action' => 'Chair adds one', 'owner_employee_id' => $this->amy->id, 'target_date' => '2026-08-20'])
            ->assertSuccessful();
        $this->assertSame(5, DB::table('tot_actions')->where('session_id', $session->id)->count());

        // An absentee needs a reason.
        $this->actingInTenantAs($this->hr)
            ->postJson("/app/tot/{$session->id}/attendance", [
                'present' => [$this->chair->id],
                'absent' => [['employee_id' => $this->staff->id, 'reason' => '']],
            ])
            ->assertStatus(422);
        $this->assertSame(20, DB::table('tot_attendance')->where('session_id', $session->id)->count(), 'a rejected save leaves the list as it was');

        // Attendance stays in the Learning module: no attendance-module row for the Saturday.
        $this->assertSame(0, DB::table('attendance_records')->count());

        // Global Clause: the session edits wrote audit rows.
        $this->assertGreaterThanOrEqual(1, AuditLog::query()->count());
    }

    // ── 2. per-slot discussion and Nota link ────────────────────────

    public function test_acceptance_2_each_slot_has_its_own_discussion_thread_and_the_session_carries_the_nota_link(): void
    {
        $session = $this->augustSession();
        [$antigravity, $postgrest] = DB::table('tot_slots')->where('session_id', $session->id)->orderBy('position')->limit(2)->get()->all();

        $this->actingInTenantAs($this->staff)
            ->postJson("/app/tot/{$session->id}/slots/{$antigravity->id}/comment", ['body' => 'Antigravity: boleh guna untuk projek RMS?'])
            ->assertSuccessful();
        $this->actingInTenantAs($this->amy)
            ->postJson("/app/tot/{$session->id}/slots/{$antigravity->id}/comment", ['body' => 'Boleh, saya tunjuk bulan depan.'])
            ->assertSuccessful();
        $this->actingInTenantAs($this->chair)
            ->postJson("/app/tot/{$session->id}/slots/{$postgrest->id}/comment", ['body' => 'PostgREST: siapa jaga auth?'])
            ->assertSuccessful();

        $this->assertSame(2, DB::table('tot_comments')->where('slot_id', $antigravity->id)->count());
        $this->assertSame(1, DB::table('tot_comments')->where('slot_id', $postgrest->id)->count());

        $thread = $this->actingInTenantAs($this->staff)
            ->getJson("/app/tot/{$session->id}/slots/{$antigravity->id}/comments")
            ->assertOk()
            ->json('comments');
        $this->assertCount(2, $thread);
        $this->assertSame(['Antigravity: boleh guna untuk projek RMS?', 'Boleh, saya tunjuk bulan depan.'], array_column($thread, 'body'));
        $this->assertSame('Shazwan', $thread[0]['name']);
        $this->assertTrue($thread[1]['presenter'], 'Amy is the presenter of the Antigravity slot');

        $other = $this->actingInTenantAs($this->staff)
            ->getJson("/app/tot/{$session->id}/slots/{$postgrest->id}/comments")
            ->assertOk()
            ->json('comments');
        $this->assertCount(1, $other);
        $this->assertSame('PostgREST: siapa jaga auth?', $other[0]['body']);

        // The old session-level thread is still separate from every slot thread.
        $sessionLevel = $this->actingInTenantAs($this->staff)
            ->getJson("/app/tot/{$session->id}/comments")
            ->assertOk()
            ->json('comments');
        $this->assertCount(0, $sessionLevel);

        // A slot from another session is not reachable through this one.
        $september = $this->septemberSession();
        $this->actingInTenantAs($this->staff)
            ->postJson("/app/tot/{$september->id}/slots/{$antigravity->id}/comment", ['body' => 'wrong door'])
            ->assertStatus(404);

        // Comments are validated the same way the session thread is.
        $this->actingInTenantAs($this->staff)
            ->postJson("/app/tot/{$session->id}/slots/{$antigravity->id}/comment", ['body' => ''])
            ->assertStatus(422);

        // Nota Perbincangan: the link section is on the page and HR can change it.
        $this->actingInTenantAs($this->staff)->get('/app/tot?year=2026')
            ->assertOk()
            ->assertSee('Nota Perbincangan')
            ->assertSee('https://drive.example.com/nota-ogos-2026.pdf');

        $this->actingInTenantAs($this->hr)
            ->post("/app/tot/{$session->id}", [
                'year' => 2026, 'month' => 8, 'status' => 'done',
                'chair_employee_id' => $this->chair->id,
                'nota_url' => 'https://drive.example.com/nota-ogos-2026-v2.pdf',
                'next_agenda' => $session->next_agenda,
            ])
            ->assertSessionHasNoErrors();
        $this->assertSame('https://drive.example.com/nota-ogos-2026-v2.pdf', $session->fresh()->nota_url);

        $this->actingInTenantAs($this->hr)
            ->postJson("/app/tot/{$session->id}", [
                'year' => 2026, 'month' => 8, 'status' => 'done',
                'chair_employee_id' => $this->chair->id,
                'nota_url' => 'not a link',
            ])
            ->assertStatus(422);
    }

    // ── 3. Create T.A.A. task on Tindakan 1 ─────────────────────────

    public function test_acceptance_3_create_taa_task_on_tindakan_1_makes_a_card_for_rubmin_due_next_month(): void
    {
        $session = $this->augustSession();
        $action = DB::table('tot_actions')->where('session_id', $session->id)->orderBy('position')->first();
        $cardsBefore = WorkItem::query()->count();

        // Plain staff who is not the owner cannot create it.
        $this->actingInTenantAs($this->staff)
            ->postJson("/app/tot/{$session->id}/actions/{$action->id}/card")
            ->assertStatus(403);
        $this->assertSame($cardsBefore, WorkItem::query()->count());

        // The chair (PM) clicks it.
        $response = $this->actingInTenantAs($this->chair)
            ->postJson("/app/tot/{$session->id}/actions/{$action->id}/card");
        $response->assertStatus(201);
        $response->assertJsonPath('ok', true);

        $card = WorkItem::query()->find($response->json('work_item.id'));
        $this->assertNotNull($card);
        $this->assertSame($this->rubmin->id, $card->employee_id, 'the card belongs to the tindakan owner');
        $this->assertSame('Sediakan dokumentasi PostgREST untuk projek RMS', $card->title);
        $this->assertSame('task', $card->type);
        $this->assertSame('todo', $card->status);
        $this->assertSame('2026-09-05', $card->due_at?->format('Y-m-d'), 'Bulan hadapan = the next TOT Saturday');
        $this->assertSame('Bulan hadapan', $card->due_label);
        $this->assertSame('2026-09-05', $response->json('work_item.due_at'));
        $this->assertSame($card->id, DB::table('tot_actions')->where('id', $action->id)->value('work_item_id'));

        // Rubmin sees it on his board, and it shows the target label.
        $this->actingInTenantAs($this->rubmin)->get('/app/board')
            ->assertOk()
            ->assertSee('Sediakan dokumentasi PostgREST untuk projek RMS');

        // Dates contract Rule 4: the card's due date is locked from now on.
        $this->actingInTenantAs($this->rubmin)
            ->patchJson("/app/board/{$card->id}", ['due_at' => '2026-10-03'])
            ->assertStatus(422);
        $this->assertSame('2026-09-05', $card->fresh()->due_at?->format('Y-m-d'));

        // No Event was created for it.
        $this->assertSame(0, DB::table('company_events')->count());

        // Clicking again does not make a second card.
        $this->actingInTenantAs($this->chair)
            ->postJson("/app/tot/{$session->id}/actions/{$action->id}/card")
            ->assertStatus(422);
        $this->assertSame($cardsBefore + 1, WorkItem::query()->count());

        // A tindakan with a real target date uses it, and the owner may create their own.
        $dated = DB::table('tot_actions')->where('session_id', $session->id)->where('position', 2)->first();
        $this->assertSame('2026-08-15', Carbon::parse($dated->target_date)->format('Y-m-d'));
        $owner = Employee::query()->find($dated->owner_employee_id);
        $second = $this->actingInTenantAs($owner)
            ->postJson("/app/tot/{$session->id}/actions/{$dated->id}/card")
            ->assertStatus(201);
        $secondCard = WorkItem::query()->find($second->json('work_item.id'));
        $this->assertSame('2026-08-15', $secondCard->due_at?->format('Y-m-d'));
        $this->assertNotSame('Bulan hadapan', (string) $secondCard->due_label);

        // A tindakan without an owner cannot become a card.
        $ownerless = $this->actingInTenantAs($this->hr)
            ->postJson("/app/tot/{$session->id}/actions", ['action' => 'Tiada pemilik'])
            ->assertSuccessful()
            ->json('id');
        $this->actingInTenantAs($this->chair)
            ->postJson("/app/tot/{$session->id}/actions/{$ownerless}/card")
            ->assertStatus(422);
        $this->assertSame($cardsBefore + 2, WorkItem::query()->count());

        // Global Clause: creating the card is audited.
        $this->assertTrue(
            AuditLog::query()->where('subject_id', $card->id)->exists()
            || AuditLog::query()->where('action', 'like', '%T.A.A.%')->exists()
            || AuditLog::query()->where('action', 'like', '%tindakan%')->exists()
            || AuditLog::query()->where('action', 'like', '%Tindakan%')->exists(),
            'no audit row for the card created from Tindakan 1'
        );
    }

    // ── 4. September carries August's agenda ────────────────────────

    public function test_acceptance_4_september_session_shows_the_agenda_carried_from_august(): void
    {
        $this->augustSession();
        $september = $this->septemberSession();

        $this->assertNull($september->description, 'nothing is copied into September');
        $this->assertNull($september->next_agenda);

        $page = $this->actingInTenantAs($this->staff)->get('/app/tot?year=2026');
        $page->assertOk();
        $page->assertSee('Agenda dari bulan lepas');
        $page->assertSee('Amy: Antigravity untuk projek RMS');
        $page->assertSee('Rubmin/Syafiq: sambungan PostgREST');
        $page->assertSeeInOrder(['Amy: Antigravity untuk projek RMS', 'Rubmin/Syafiq: sambungan PostgREST']);

        // Editing August's agenda later still shows through, because September reads it live.
        $august = TotSession::query()->where('year', 2026)->where('month', 8)->firstOrFail();
        $this->actingInTenantAs($this->hr)
            ->post("/app/tot/{$august->id}", [
                'year' => 2026, 'month' => 8, 'status' => 'done',
                'chair_employee_id' => $this->chair->id,
                'nota_url' => $august->nota_url,
                'next_agenda' => "Amy: Antigravity untuk projek RMS\nRubmin/Syafiq: sambungan PostgREST\nShazwan: Obscura",
            ])
            ->assertSessionHasNoErrors();
        $this->actingInTenantAs($this->staff)->get('/app/tot?year=2026')
            ->assertOk()
            ->assertSee('Shazwan: Obscura');

        // A month with nothing before it shows no carried agenda.
        TotSession::query()->where('year', 2026)->where('month', 8)->update(['next_agenda' => null]);
        $this->actingInTenantAs($this->staff)->get('/app/tot?year=2026')
            ->assertOk()
            ->assertDontSee('Agenda dari bulan lepas');
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

    /** Builds the 1 Ogos 2026 session through the app, as HR would. */
    private function augustSession(): TotSession
    {
        $id = $this->actingInTenantAs($this->hr)
            ->postJson('/app/tot', [
                'year' => 2026, 'month' => 8, 'status' => 'done',
                'presenter_employee_ids' => [$this->amy->id],
            ])
            ->assertSuccessful()
            ->json('id');

        $this->actingInTenantAs($this->hr)
            ->post("/app/tot/{$id}", [
                'year' => 2026, 'month' => 8, 'status' => 'done',
                'chair_employee_id' => $this->chair->id,
                'nota_url' => 'https://drive.example.com/nota-ogos-2026.pdf',
                'next_agenda' => "Amy: Antigravity untuk projek RMS\nRubmin/Syafiq: sambungan PostgREST",
            ])
            ->assertSessionHasNoErrors();

        $this->slot($id, ['title' => 'Google AI Antigravity', 'kind' => 'pembentangan', 'format' => 'slide', 'status' => 'rasmi', 'presenter_mode' => 'solo', 'presenters' => [$this->amy->id], 'summary' => 'Agent IDE dari Google.']);
        $this->slot($id, ['title' => 'PostgREST - Building REST APIs Directly from PostgreSQL', 'kind' => 'pembentangan', 'format' => 'demo', 'status' => 'ujian', 'presenter_mode' => 'team', 'presenters' => [$this->rubmin->id], 'support' => [$this->syafiq->id]]);
        $this->slot($id, ['title' => 'Graphify vs Repomix', 'kind' => 'demonstrasi', 'format' => 'demo', 'status' => 'ujian', 'presenters' => [$this->staff->id]]);
        $this->slot($id, ['title' => 'Sambungan: Laravel AI chatbox', 'kind' => 'sambungan', 'format' => 'demo', 'status' => 'rasmi', 'presenters' => [$this->syafiq->id]]);

        $present = array_values(array_filter(array_map(fn (Employee $e) => $e->id, $this->roster), fn (int $id) => $id !== $this->staff->id));
        $this->actingInTenantAs($this->hr)
            ->postJson("/app/tot/{$id}/attendance", [
                'present' => $present,
                'absent' => [['employee_id' => $this->staff->id, 'reason' => 'Cuti sakit']],
            ])
            ->assertSuccessful();

        $postgrestSlot = DB::table('tot_slots')->where('session_id', $id)->where('position', 2)->value('id');
        $this->action($id, ['action' => 'Sediakan dokumentasi PostgREST untuk projek RMS', 'owner_employee_id' => $this->rubmin->id, 'slot_id' => $postgrestSlot]);
        $this->action($id, ['action' => 'Kemaskini slaid Antigravity', 'owner_employee_id' => $this->amy->id, 'target_date' => '2026-08-15']);
        $this->action($id, ['action' => 'Uji Graphify pada repo Amanahku', 'owner_employee_id' => $this->staff->id]);
        $this->action($id, ['action' => 'Sambung demo chatbox bulan depan', 'owner_employee_id' => $this->syafiq->id]);

        return TotSession::query()->findOrFail($id);
    }

    private function septemberSession(): TotSession
    {
        $id = $this->actingInTenantAs($this->hr)
            ->postJson('/app/tot', [
                'year' => 2026, 'month' => 9, 'status' => 'planned',
                'presenter_employee_ids' => [$this->amy->id],
            ])
            ->assertSuccessful()
            ->json('id');

        return TotSession::query()->findOrFail($id);
    }

    /** @param array<string, mixed> $fields */
    private function slot(int $sessionId, array $fields): TestResponse
    {
        return $this->actingInTenantAs($this->hr)
            ->postJson("/app/tot/{$sessionId}/slots", $fields)
            ->assertSuccessful();
    }

    /** @param array<string, mixed> $fields */
    private function action(int $sessionId, array $fields): TestResponse
    {
        return $this->actingInTenantAs($this->hr)
            ->postJson("/app/tot/{$sessionId}/actions", $fields)
            ->assertSuccessful();
    }
}
