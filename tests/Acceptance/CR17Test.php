<?php

namespace Tests\Acceptance;

use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Project;
use App\Models\Shift;
use App\Models\WorkItem;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Acceptance for docs/specs/CR-17.md (session S15, Management view on the dashboard:
 * lateness and overdue tasks). Shapes fixed in OPEN "QA / CR-17 / shapes fixed by CR17Test":
 * the CR-32 `management` band carries two panels (`data-panel="lateness"`, `data-panel=
 * "overdue"`) for `Permissions::FINAL_APPROVAL_ROLES`; a branch-or-wider `manager` (the
 * contract's senior manager) never gets the band (CR32Test pins that) and reads the same
 * panels, scoped to their reporting line, at `GET /app/management/exceptions`; nudge and
 * reassign live under `/app/management/overdue/{card}`; lateness reads `attendance_records`
 * with a confirmed `shifts` row as the approved flexi start; HR marks incident windows at
 * `POST /app/attendance/incidents`; the 08:00 digest is a `port_outbox` mail intent.
 */
class CR17Test extends TestCase
{
    use AlwaysChecks;
    use RefreshDatabase;

    private const TODAY = '2026-09-08';

    private Employee $director;

    private Employee $hr;

    private Employee $yati;

    private Employee $kussairi;

    private Employee $otherManager;

    private Employee $emysha;

    private Employee $adri;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(self::TODAY.' 14:00:00');

        $this->director = $this->person('Shahril', 'director');
        $this->hr = $this->person('Hidayah', 'hr');
        // Yati is the contract's senior manager: role manager, data scope branch.
        $this->yati = $this->person('Yati', 'manager');
        $this->yati->user->tenants()->updateExistingPivot($this->tenant()->id, ['data_scope' => 'branch']);
        $this->kussairi = $this->person('Kussairi', 'manager', ['reports_to_id' => $this->yati->id]);
        $this->kussairi->user->tenants()->updateExistingPivot($this->tenant()->id, ['data_scope' => 'team']);
        $this->otherManager = $this->person('Faiz', 'manager');
        $this->emysha = $this->person('Emysha', 'employee', ['reports_to_id' => $this->kussairi->id]);
        $this->adri = $this->person('Adri', 'employee', ['reports_to_id' => $this->otherManager->id]);
    }

    // ── 1. Director: both panels, company-wide ───────────────────────

    public function test_acceptance_1_director_dashboard_opens_with_lateness_and_overdue_panels_company_wide(): void
    {
        $this->lateRecord($this->emysha, '13:14:00');
        $this->lateRecord($this->adri, '09:40:00');
        $emyshaCard = $this->overdueCard($this->emysha, 'Prototype P2 SMK', 7);
        $adriCard = $this->overdueCard($this->adri, 'Site survey report', 2);

        $page = $this->actingInTenantAs($this->director)->get('/app/dash')->assertOk();
        $html = $page->getContent();

        $page->assertSee('data-band="management"', false)
            ->assertSee('data-panel="lateness"', false)
            ->assertSee('data-panel="overdue"', false);

        // The band sits above the widget grid.
        $this->assertLessThan(strpos($html, 'uj-dw-grid'), strpos($html, 'data-band="management"'), 'panels must sit above the month summary');

        // Company-wide: both branches of the org appear in both panels.
        foreach ([$this->emysha, $this->adri] as $person) {
            $page->assertSee('data-late-row="'.$person->id.'"', false);
            $page->assertSee('data-overdue-owner="'.$person->id.'"', false);
        }
        $page->assertSee('data-card="'.$emyshaCard->id.'"', false)
            ->assertSee('data-card="'.$adriCard->id.'"', false)
            ->assertSee('Late 4h14m')
            ->assertSee('Late 0h40m');

        // The panels do not move any existing card: baseline order still holds below them.
        $this->assertMatchesRegularExpression('/data-widget="summary".*data-widget="clock".*data-widget="tasks"/s', $html);
    }

    // ── 2. Senior manager: same panels, scoped to her staff ──────────

    public function test_acceptance_2_yati_sees_the_same_panels_scoped_to_her_reporting_line(): void
    {
        $this->lateRecord($this->emysha, '13:14:00');
        $this->lateRecord($this->adri, '09:40:00');
        $emyshaCard = $this->overdueCard($this->emysha, 'Prototype P2 SMK', 7);
        $adriCard = $this->overdueCard($this->adri, 'Site survey report', 2);

        // Contract (dashboard-slots, roles): the band is FINAL_APPROVAL_ROLES only; CR32Test
        // pins that a manager never sees it. So Yati reads the panels on their own page.
        $this->actingInTenantAs($this->yati)->get('/app/dash')->assertOk()->assertDontSee('data-band="management"', false);

        $page = $this->actingInTenantAs($this->yati)->get('/app/management/exceptions')->assertOk();
        $page->assertSee('data-panel="lateness"', false)
            ->assertSee('data-panel="overdue"', false)
            ->assertSee('data-late-row="'.$this->emysha->id.'"', false)
            ->assertSee('data-card="'.$emyshaCard->id.'"', false)
            ->assertDontSee('data-late-row="'.$this->adri->id.'"', false)
            ->assertDontSee('data-card="'.$adriCard->id.'"', false);

        // A team-scoped manager and plain staff have no such page.
        $this->actingInTenantAs($this->kussairi)->get('/app/management/exceptions')->assertStatus(403);
        $this->actingInTenantAs($this->emysha)->get('/app/management/exceptions')->assertStatus(403);

        // Director and HR read the page company-wide, and can narrow it to their own line.
        $this->actingInTenantAs($this->director)->get('/app/management/exceptions')->assertOk()
            ->assertSee('data-card="'.$emyshaCard->id.'"', false)
            ->assertSee('data-card="'.$adriCard->id.'"', false);
        $this->actingInTenantAs($this->hr)->get('/app/management/exceptions')->assertOk()
            ->assertSee('data-late-row="'.$this->adri->id.'"', false);
    }

    // ── 3. Staff: no panels ──────────────────────────────────────────

    public function test_acceptance_3_emysha_sees_no_panels(): void
    {
        $this->lateRecord($this->adri, '09:40:00');
        $this->overdueCard($this->adri, 'Site survey report', 2);

        $page = $this->actingInTenantAs($this->emysha)->get('/app/dash')->assertOk();
        $page->assertDontSee('data-band="management"', false)
            ->assertDontSee('data-panel=', false)
            ->assertDontSee('Site survey report')
            ->assertDontSee('data-late-row=', false);

        // Nor can staff reach the figures or the actions by URL.
        $this->actingInTenantAs($this->emysha)->get('/app/management/exceptions')->assertStatus(403);
        $card = WorkItem::where('title', 'Site survey report')->firstOrFail();
        $this->actingInTenantAs($this->emysha)->postJson("/app/management/overdue/{$card->id}/nudge")->assertStatus(403);
    }

    // ── 4. Overdue card under its assignee, nudge ────────────────────

    public function test_acceptance_4_prototype_p2_smk_shows_seven_days_overdue_and_a_nudge_notifies_the_assignee(): void
    {
        $card = $this->overdueCard($this->emysha, 'Prototype P2 SMK', 7, ['priority' => 'high']);

        $page = $this->actingInTenantAs($this->director)->get('/app/dash')->assertOk();
        $html = $page->getContent();
        $owner = strpos($html, 'data-overdue-owner="'.$this->emysha->id.'"');
        $this->assertNotFalse($owner);
        $this->assertStringContainsString('data-card="'.$card->id.'"', substr($html, $owner), 'card not under its assignee');
        $page->assertSee('7 days overdue')->assertSee('data-nudge-url=', false);

        $notificationsBefore = DB::table('app_notifications')->where('user_id', $this->emysha->user_id)->count();

        $this->actingInTenantAs($this->director)
            ->postJson("/app/management/overdue/{$card->id}/nudge")
            ->assertOk();

        $this->assertSame($notificationsBefore + 1, DB::table('app_notifications')->where('user_id', $this->emysha->user_id)->count());
        $this->assertTrue(
            DB::table('app_notifications')->where('user_id', $this->emysha->user_id)->where('title', 'like', '%Prototype P2 SMK%')->exists(),
            'nudge did not name the card'
        );
        $this->assertTrue(
            AuditLog::where('tenant_id', $this->tenant()->id)->where('subject_type', WorkItem::class)->where('subject_id', $card->id)
                ->where('action', 'like', '%udge%')->exists(),
            'nudge not audited'
        );

        // Max one nudge per card per day; tomorrow it opens again.
        $this->actingInTenantAs($this->director)->postJson("/app/management/overdue/{$card->id}/nudge")->assertStatus(422);
        $this->assertSame($notificationsBefore + 1, DB::table('app_notifications')->where('user_id', $this->emysha->user_id)->count());
        Carbon::setTestNow('2026-09-09 09:00:00');
        $this->actingInTenantAs($this->director)->postJson("/app/management/overdue/{$card->id}/nudge")->assertOk();

        // HR may nudge too; a done card cannot be nudged.
        Carbon::setTestNow('2026-09-10 09:00:00');
        $this->actingInTenantAs($this->hr)->postJson("/app/management/overdue/{$card->id}/nudge")->assertOk();
        $card->update(['status' => 'done', 'done_at' => now()]);
        Carbon::setTestNow('2026-09-11 09:00:00');
        $this->actingInTenantAs($this->director)->postJson("/app/management/overdue/{$card->id}/nudge")->assertStatus(422);
    }

    // ── 5. Late 4h14m vs approved flexi shift ────────────────────────

    public function test_acceptance_5_a_13_14_clock_in_is_late_4h14m_on_a_9am_shift_and_on_time_on_an_approved_13_00_flexi_shift(): void
    {
        $this->lateRecord($this->emysha, '13:14:00');
        $this->lateRecord($this->adri, '13:14:00');
        Shift::create([
            'tenant_id' => $this->tenant()->id, 'employee_id' => $this->adri->id,
            'date' => self::TODAY, 'start_time' => '13:00:00', 'end_time' => '22:00:00',
            'location' => 'HQ', 'status' => 'confirmed',
        ]);

        $html = $this->actingInTenantAs($this->director)->get('/app/dash')->assertOk()->getContent();

        $this->assertStringContainsString('Late 4h14m', $this->row($html, $this->emysha));
        $this->assertStringContainsString('On time', $this->row($html, $this->adri));
        $this->assertStringNotContainsString('Late', $this->row($html, $this->adri));

        // A shift that is only scheduled, not confirmed, is not an approved flexi.
        Shift::where('employee_id', $this->adri->id)->update(['status' => 'scheduled']);
        $html = $this->actingInTenantAs($this->director)->get('/app/dash')->assertOk()->getContent();
        $this->assertStringContainsString('Late 4h14m', $this->row($html, $this->adri));
    }

    // ── 6. Leave, WFH, client site: not late ─────────────────────────

    public function test_acceptance_6_staff_on_approved_leave_wfh_or_client_site_are_not_in_the_late_list(): void
    {
        $onLeave = $this->person('Leila', 'employee', ['reports_to_id' => $this->kussairi->id]);
        $wfh = $this->person('Wan', 'employee', ['reports_to_id' => $this->kussairi->id]);
        $client = $this->person('Cik', 'employee', ['reports_to_id' => $this->kussairi->id]);
        $notIn = $this->person('Nadia', 'employee', ['reports_to_id' => $this->kussairi->id]);

        $type = LeaveType::create(['tenant_id' => $this->tenant()->id, 'name' => 'Annual', 'entitlement' => 18]);
        LeaveRequest::create([
            'tenant_id' => $this->tenant()->id, 'employee_id' => $onLeave->id, 'leave_type_id' => $type->id,
            'date_from' => self::TODAY, 'date_to' => self::TODAY, 'days' => 1, 'status' => 'approved', 'reason' => 'Family',
        ]);
        AttendanceRecord::create([
            'tenant_id' => $this->tenant()->id, 'employee_id' => $wfh->id, 'date' => self::TODAY,
            'clock_in' => '10:30:00', 'expected_start' => '09:00:00', 'status' => 'late', 'type' => 'wfh', 'flags' => ['late'],
        ]);
        AttendanceRecord::create([
            'tenant_id' => $this->tenant()->id, 'employee_id' => $client->id, 'date' => self::TODAY,
            'clock_in' => '10:30:00', 'expected_start' => '09:00:00', 'status' => 'late', 'type' => 'client', 'flags' => ['late'],
        ]);
        $this->lateRecord($this->emysha, '09:30:00');

        $html = $this->actingInTenantAs($this->director)->get('/app/dash')->assertOk()->getContent();

        foreach ([$onLeave, $wfh, $client] as $person) {
            $this->assertStringNotContainsString('data-late-row="'.$person->id.'"', $html, "{$person->name} listed as late");
        }
        // Someone who has simply not clocked in is listed, as not clocked in, without a minutes figure.
        $this->assertStringContainsString('data-late-row="'.$notIn->id.'"', $html);
        $this->assertStringContainsString('Not clocked in', $this->row($html, $notIn));
        $this->assertStringContainsString('Late 0h30m', $this->row($html, $this->emysha));
    }

    // ── 7. Helper is not the owner ───────────────────────────────────

    public function test_acceptance_7_a_card_owned_by_adri_with_emysha_as_helper_appears_under_adri_only(): void
    {
        $card = $this->overdueCard($this->adri, 'Client onboarding deck', 3);
        $card->participants()->attach($this->emysha->id, ['role' => 'helper']);

        $html = $this->actingInTenantAs($this->director)->get('/app/dash')->assertOk()->getContent();

        $this->assertStringContainsString('data-overdue-owner="'.$this->adri->id.'"', $html);
        $this->assertStringNotContainsString('data-overdue-owner="'.$this->emysha->id.'"', $html);
        $this->assertSame(1, substr_count($html, 'data-card="'.$card->id.'"'), 'card listed more than once');

        // Yati's line (Emysha) does not inherit the card through the helper tag.
        $this->actingInTenantAs($this->yati)->get('/app/management/exceptions')->assertOk()
            ->assertDontSee('data-card="'.$card->id.'"', false);
    }

    // ── 8. Reassign by the line manager, audited, overdue stays on record ──

    public function test_acceptance_8_emyshas_line_manager_reassigns_her_overdue_card_with_a_reason_and_september_stays_against_emysha(): void
    {
        $project = Project::create(['tenant_id' => $this->tenant()->id, 'code' => 'SMK', 'name' => 'SMK Portal', 'pm_id' => $this->otherManager->id]);
        $card = $this->overdueCard($this->emysha, 'Prototype P2 SMK', 7, ['project_id' => $project->id]);
        $url = "/app/management/overdue/{$card->id}/reassign";
        $body = ['employee_id' => $this->adri->id, 'reason' => 'Emysha is on the SMK go-live this week.'];

        // HR sees lateness company-wide but cannot reassign; a manager outside the line cannot either.
        $this->actingInTenantAs($this->hr)->postJson($url, $body)->assertStatus(403);
        $unrelated = $this->person('Zul', 'manager');
        $this->actingInTenantAs($unrelated)->postJson($url, $body)->assertStatus(403);

        // Reason is required.
        $this->actingInTenantAs($this->kussairi)->postJson($url, ['employee_id' => $this->adri->id])
            ->assertStatus(422)->assertJsonValidationErrors(['reason']);
        $this->assertSame($this->emysha->id, $card->fresh()->employee_id);

        // The line manager may.
        $this->actingInTenantAs($this->kussairi)->postJson($url, $body)->assertOk();

        $card->refresh();
        $this->assertSame($this->adri->id, $card->employee_id);
        $this->assertSame('2026-09-01', $card->due_at->format('Y-m-d'), 'due date must not move on reassign');

        $audit = AuditLog::where('tenant_id', $this->tenant()->id)->where('subject_type', WorkItem::class)
            ->where('subject_id', $card->id)->where('field', 'employee_id')->latest('id')->first();
        $this->assertNotNull($audit, 'reassign not audited');
        $this->assertSame((string) $this->emysha->id, (string) $audit->old_value);
        $this->assertSame((string) $this->adri->id, (string) $audit->new_value);
        $this->assertSame($body['reason'], $audit->reason);
        $this->assertSame($this->kussairi->user_id, $audit->user_id);

        // Both owners are told.
        foreach ([$this->emysha, $this->adri] as $person) {
            $this->assertTrue(
                DB::table('app_notifications')->where('user_id', $person->user_id)->where('title', 'like', '%Prototype P2 SMK%')->exists(),
                "{$person->name} not notified"
            );
        }

        // CR-14 rule 8: the September overdue stays on Emysha's record even though the card moved.
        $this->assertDatabaseHas('overdue_ledger', [
            'tenant_id' => $this->tenant()->id, 'work_item_id' => $card->id,
            'employee_id' => $this->emysha->id, 'month' => '2026-09-01',
        ]);

        // The PM of the card's project and the Director may reassign too; the new owner's own line manager is not needed.
        $this->actingInTenantAs($this->otherManager)->postJson($url, ['employee_id' => $this->emysha->id, 'reason' => 'Back to Emysha.'])->assertOk();
        $this->actingInTenantAs($this->director)->postJson($url, ['employee_id' => $this->adri->id, 'reason' => 'Director call.'])->assertOk();
        $this->assertSame($this->adri->id, $card->fresh()->employee_id);
    }

    // ── 9. Incident window: Unverified ───────────────────────────────

    public function test_acceptance_9_hr_marks_an_incident_window_and_clock_records_inside_it_show_unverified(): void
    {
        $this->lateRecord($this->emysha, '09:20:00');
        $this->lateRecord($this->adri, '11:05:00');

        $window = ['starts_at' => self::TODAY.' 09:00', 'ends_at' => self::TODAY.' 10:00', 'note' => 'Clock server down.'];

        // Only HR marks a window.
        $this->actingInTenantAs($this->director)->postJson('/app/attendance/incidents', $window)->assertStatus(403);
        $this->actingInTenantAs($this->emysha)->postJson('/app/attendance/incidents', $window)->assertStatus(403);
        $this->actingInTenantAs($this->hr)->postJson('/app/attendance/incidents', ['starts_at' => self::TODAY.' 10:00', 'ends_at' => self::TODAY.' 09:00'])
            ->assertStatus(422);

        $this->actingInTenantAs($this->hr)->postJson('/app/attendance/incidents', $window)->assertSuccessful();
        $this->assertDatabaseHas('attendance_incidents', ['tenant_id' => $this->tenant()->id, 'note' => 'Clock server down.']);
        $this->assertTrue(AuditLog::where('tenant_id', $this->tenant()->id)->where('action', 'like', '%ncident%')->exists(), 'incident window not audited');

        $html = $this->actingInTenantAs($this->director)->get('/app/dash')->assertOk()->getContent();
        $this->assertStringContainsString('Unverified', $this->row($html, $this->emysha));
        $this->assertStringNotContainsString('Late 0h20m', $this->row($html, $this->emysha));
        $this->assertStringContainsString('Late 2h05m', $this->row($html, $this->adri));
        $this->assertStringNotContainsString('Unverified', $this->row($html, $this->adri));
    }

    // ── DEFERRED: the 08:00 digest goes through MailPort into the outbox ──

    public function test_deferred_the_8am_digest_is_a_mail_port_intent_in_the_outbox_plus_an_in_app_notice(): void
    {
        Mail::fake();
        Http::fake();
        $this->lateRecord($this->emysha, '09:40:00');
        $this->lateRecord($this->adri, '09:50:00');
        $this->overdueCard($this->emysha, 'Prototype P2 SMK', 7);
        $this->overdueCard($this->adri, 'Site survey report', 2);
        $this->overdueCard($this->adri, 'Vendor quote', 1);

        $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains($e->command ?? '', 'management:digest'));
        $this->assertCount(1, $events, 'management:digest is not in the scheduler');
        $this->assertStringStartsWith('0 8 * * *', $events->first()->expression, 'management:digest does not run daily at 08:00');

        Carbon::setTestNow(self::TODAY.' 08:00:00');
        Artisan::call('management:digest');

        $rows = DB::table('port_outbox')->where('tenant_id', $this->tenant()->id)->where('port', 'mail')->where('method', 'send')->get();
        $this->assertGreaterThanOrEqual(1, $rows->count(), 'no mail intent in the outbox');
        $recipients = $rows->flatMap(fn ($r) => json_decode($r->payload, true)['to'] ?? [])->unique()->values();
        foreach ([$this->director, $this->hr] as $person) {
            $this->assertContains($person->user->email, $recipients->all(), "{$person->name} not on the digest");
        }
        foreach ([$this->emysha, $this->kussairi, $this->yati] as $person) {
            $this->assertNotContains($person->user->email, $recipients->all(), "{$person->name} must not get the digest");
        }
        $payload = json_decode($rows->first()->payload, true);
        $this->assertSame('management_digest', $payload['kind']);
        $this->assertMatchesRegularExpression('/\b2\b.*late/is', $payload['body_en']);
        $this->assertMatchesRegularExpression('/\b3\b.*overdue/is', $payload['body_en']);
        $this->assertNotSame('', $payload['body_ms']);

        foreach ([$this->director, $this->hr] as $person) {
            $this->assertTrue(DB::table('app_notifications')->where('user_id', $person->user_id)->where('title', 'like', '%digest%')->exists(), "{$person->name} has no in-app digest");
        }

        // Running it twice on the same morning does not send twice.
        Artisan::call('management:digest');
        $this->assertSame($rows->count(), DB::table('port_outbox')->where('tenant_id', $this->tenant()->id)->where('port', 'mail')->count());

        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Http::assertNothingSent();
    }

    // ── every-session checks ─────────────────────────────────────────

    public function test_always_checks(): void
    {
        $this->assertDueDateLocked();
        $this->assertAuditLogImmutable();
        $this->assertDashboardUnchanged();
        $this->assertKeepItPlainHonoured();
    }

    // ── helpers ──────────────────────────────────────────────────────

    /** A standard-site clock-in today against a 09:00 shift. */
    private function lateRecord(Employee $employee, string $clockIn): AttendanceRecord
    {
        $late = $clockIn > '09:10:00';

        return AttendanceRecord::create([
            'tenant_id' => $this->tenant()->id, 'employee_id' => $employee->id, 'date' => self::TODAY,
            'clock_in' => $clockIn, 'expected_start' => '09:00:00', 'expected_end' => '18:00:00',
            'status' => $late ? 'late' : 'on_time', 'type' => 'standard', 'flags' => $late ? ['late'] : [],
        ]);
    }

    /** A card whose locked due date passed $daysAgo days ago. */
    private function overdueCard(Employee $owner, string $title, int $daysAgo, array $attrs = []): WorkItem
    {
        return $this->card($owner, array_merge([
            'title' => $title, 'status' => 'in_progress', 'priority' => 'medium',
            'due_at' => Carbon::parse(self::TODAY)->subDays($daysAgo)->toDateString(),
        ], $attrs));
    }

    /** The lateness row markup for one person. */
    private function row(string $html, Employee $employee): string
    {
        $start = strpos($html, 'data-late-row="'.$employee->id.'"');
        $this->assertNotFalse($start, "{$employee->name} has no lateness row");
        $end = strpos($html, 'data-late-row=', $start + 10);

        return substr($html, $start, $end === false ? 600 : $end - $start);
    }
}
