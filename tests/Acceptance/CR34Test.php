<?php

namespace Tests\Acceptance;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Project;
use App\Models\PublicHoliday;
use App\Models\WorkItem;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Acceptance for docs/specs/CR-34.md (session S16, the internal half: the Friday morning
 * T.A.A. task per manager, the deferred 3 PM reminder as a MailPort intent, the holiday
 * shift and the HR pause). The Track meeting pack (scope 5) and Track AI (item 6) are
 * Track-side and stay outside the run.
 *
 * Shapes this file fixes for the generator (recorded in docs/build/OPEN.md):
 * - Two daily commands on the app clock, registered in the scheduler:
 *   `management:meeting-tasks` at `0 8 * * *` and `management:meeting-reminder` at
 *   `0 15 * * *`. Each decides for itself whether today is the task day: the tenant's
 *   meeting day (default Friday), or the working day before it when the meeting day is a
 *   `public_holidays` row; nothing on any other day; nothing while paused.
 * - Recipients (same set for cards and mail): every active, non-archived employee who is
 *   `pm_id` or `pe_id` on a project that is still active (`is_active` true and not closed),
 *   plus every active employee whose membership role is in the tenant's attendee roles
 *   (default manager, hr, management, director). One card and one mail per person, however
 *   many projects they hold. Archived people and plain employees get nothing.
 * - The card: type task, title 'Update Track for management meeting', priority medium,
 *   status todo, `employee_id` = the manager, `assigned_by_id` null, no participants,
 *   `due_at` = the meeting date (the moved date on a holiday week), `project_id` = the
 *   tenant's project named 'URSB : Management meeting' (created by the engine if missing),
 *   labels containing `system` (a new `WorkItem::LABELS` key), and the CR-19 marker
 *   `work_items.source = 'management_meeting'` with `source_ref` = the due date
 *   ('YYYY-MM-DD'). `source` is a new nullable string column, null on every manual card;
 *   `source_ref` a nullable string. One card per person per `source_ref`, however often the
 *   command runs. Creation is audited on the card.
 * - The reminder (DEFERRED half): one `MailPort::send` per tenant per meeting date, so one
 *   `port_outbox` row (`port` mail, `method` send, payload `kind` =
 *   'management_meeting_reminder', `to` = every recipient's email, `subject` exactly
 *   'Management meeting today 5 PM, update your Track', `body_en` containing "Hello managers,
 *   let's prep for the management meeting. Please update your Track entry before 5 PM." and
 *   the words 'Open Track', `body_ms` filled), one `app_notifications` row per recipient whose
 *   title contains 'Management meeting', and one audit row whose action contains 'meeting'
 *   (every send logged). A second run the same day adds nothing.
 * - Overdue after 5 PM: `ManagementExceptions::overdue()` lists a `management_meeting` card
 *   from the tenant's meeting time on its due date ('0 days overdue' that evening), not only
 *   from the next day; a done card is never listed. The card carries the marker CR-14a must
 *   read to keep it out of every award.
 * - Settings: `POST /app/admin/management-meeting` with any of `meeting_day` (1 Monday to
 *   7 Sunday), `meeting_time` ('17:00'), `reminder_time` ('15:00'), `task_time` ('08:00'),
 *   `attendee_roles` (list of membership roles), `paused_until` (date or null). Allowed for
 *   `Permissions::FINAL_APPROVAL_ROLES` (management, director, hr); everyone else 403. Each
 *   change writes an audit row. Stored per tenant (one row, defaults when absent).
 */
class CR34Test extends TestCase
{
    use AlwaysChecks;
    use RefreshDatabase;

    private const FRIDAY = '2026-09-11';

    private const TITLE = 'Update Track for management meeting';

    private const SUBJECT = 'Management meeting today 5 PM, update your Track';

    private const BODY = "Hello managers, let's prep for the management meeting. Please update your Track entry before 5 PM.";

    private Employee $director;

    private Employee $hr;

    private Employee $yati;

    private Employee $ahmad;

    private Employee $nurin;

    private Employee $emysha;

    private Project $meetingProject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->director = $this->person('Shahril', 'director');
        $this->hr = $this->person('Hidayah', 'hr');
        $this->yati = $this->person('Yati', 'manager');
        $this->yati->user->tenants()->updateExistingPivot($this->tenant()->id, ['data_scope' => 'branch']);
        $this->ahmad = $this->person('Ahmad', 'manager');
        $this->nurin = $this->person('Nurin');
        $this->emysha = $this->person('Emysha');

        // Ahmad is PM on two live projects, Nurin is PE on one: one card each, not per project.
        Project::create(['tenant_id' => $this->tenant()->id, 'code' => 'KPT', 'name' => 'KPT : RMS', 'pm_id' => $this->ahmad->id, 'pe_id' => $this->nurin->id]);
        Project::create(['tenant_id' => $this->tenant()->id, 'code' => 'SMK', 'name' => 'SMK Portal', 'pm_id' => $this->ahmad->id]);
        // A closed project does not make Emysha a manager.
        Project::create(['tenant_id' => $this->tenant()->id, 'code' => 'OLD', 'name' => 'Old job', 'pm_id' => $this->emysha->id, 'is_active' => false, 'closed_at' => '2026-01-31 00:00:00']);
        $this->meetingProject = Project::create(['tenant_id' => $this->tenant()->id, 'code' => 'URSB-MM', 'name' => 'URSB : Management meeting']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── 1. Friday 08:00: one card per manager, on their own board ────

    public function test_acceptance_1_friday_morning_each_manager_gets_their_own_card_due_5_pm(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains($e->command ?? '', 'management:meeting-tasks'));
        $this->assertCount(1, $events, 'management:meeting-tasks is not in the scheduler');
        $this->assertStringStartsWith('0 8 * * *', $events->first()->expression, 'management:meeting-tasks does not run daily at 08:00');

        // Thursday: nothing.
        $this->runTasks('2026-09-10 08:00:00');
        $this->assertCount(0, $this->meetingCards(), 'cards created on a non-meeting day');

        $this->runTasks(self::FRIDAY.' 08:00:00');

        $cards = $this->meetingCards();
        $this->assertEqualsCanonicalizing(
            [$this->director->id, $this->hr->id, $this->yati->id, $this->ahmad->id, $this->nurin->id],
            $cards->pluck('employee_id')->all(),
            'one card per manager (PM, PE, Sr PM, Director, HR), nobody else',
        );

        foreach ([$this->ahmad, $this->nurin, $this->yati] as $person) {
            $card = $this->cardFor($person);
            $this->assertSame(self::TITLE, $card->title);
            $this->assertSame('task', $card->type);
            $this->assertSame('todo', $card->status);
            $this->assertSame('medium', $card->priority);
            $this->assertSame(self::FRIDAY, $card->due_at?->toDateString(), 'due on the meeting day');
            $this->assertSame($this->meetingProject->id, $card->project_id, "project tag 'URSB : Management meeting'");
            $this->assertContains('system', $card->labels ?? [], "label 'System'");
            $this->assertSame('management_meeting', $card->source);
            $this->assertSame(self::FRIDAY, $card->source_ref);
            $this->assertNull($card->assigned_by_id, 'system cards have no human creator');
            $this->assertNull($card->parent_id);
            $this->assertSame(0, DB::table('work_item_participant')->where('work_item_id', $card->id)->count(), 'not a group task');
            $this->assertTrue(AuditLog::where('tenant_id', $this->tenant()->id)->where('subject_type', WorkItem::class)->where('subject_id', $card->id)->exists(), 'card creation not audited');
        }
        $this->assertArrayHasKey('system', WorkItem::LABELS);

        // Own boards only.
        $ahmadCard = $this->cardFor($this->ahmad);
        $nurinCard = $this->cardFor($this->nurin);
        $this->actingInTenantAs($this->ahmad)->get('/app/board')->assertOk()
            ->assertSee('data-id="'.$ahmadCard->id.'"', false)
            ->assertDontSee('data-id="'.$nurinCard->id.'"', false);
        $this->actingInTenantAs($this->nurin)->get('/app/board')->assertOk()
            ->assertSee('data-id="'.$nurinCard->id.'"', false)
            ->assertDontSee('data-id="'.$ahmadCard->id.'"', false);
        $this->actingInTenantAs($this->emysha)->get('/app/board')->assertOk()
            ->assertDontSee(self::TITLE);

        // Running again the same morning, or later that day, never doubles up.
        $this->runTasks(self::FRIDAY.' 08:00:00');
        $this->runTasks(self::FRIDAY.' 11:30:00');
        $this->assertCount(5, $this->meetingCards(), 'a second run created more cards');
    }

    // ── 2. Friday 15:00: the same generic email to every manager (DEFERRED) ──

    public function test_acceptance_2_friday_3pm_reminder_is_a_mail_port_intent_to_every_manager(): void
    {
        Mail::fake();
        Http::fake();

        $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains($e->command ?? '', 'management:meeting-reminder'));
        $this->assertCount(1, $events, 'management:meeting-reminder is not in the scheduler');
        $this->assertStringStartsWith('0 15 * * *', $events->first()->expression, 'management:meeting-reminder does not run daily at 15:00');

        $this->runReminder('2026-09-10 15:00:00');
        $this->assertSame(0, $this->reminderRows()->count(), 'a reminder went out on a non-meeting day');

        $this->runReminder(self::FRIDAY.' 15:00:00');

        $rows = $this->reminderRows();
        $this->assertCount(1, $rows, 'exactly one MailPort intent per tenant');
        $row = $rows->first();
        $this->assertSame('sent', $row->status, 'stub adapter marks the intent sent');
        $this->assertSame($this->tenant()->id, (int) $row->tenant_id);

        $payload = json_decode($row->payload, true);
        $this->assertSame(self::SUBJECT, $payload['subject']);
        $this->assertStringContainsString(self::BODY, $payload['body_en']);
        $this->assertStringContainsString('Open Track', $payload['body_en'], "the one 'Open Track' button");
        $this->assertNotSame('', trim((string) ($payload['body_ms'] ?? '')), 'BM body empty');
        $this->assertStringNotContainsString('KPT : RMS', $payload['body_en'], 'no per-project lists');
        $this->assertEqualsCanonicalizing(
            [$this->director->user->email, $this->hr->user->email, $this->yati->user->email, $this->ahmad->user->email, $this->nurin->user->email],
            $payload['to'],
            'one email per manager, nobody else',
        );

        foreach ([$this->director, $this->hr, $this->yati, $this->ahmad, $this->nurin] as $person) {
            $this->assertSame(1, DB::table('app_notifications')->where('user_id', $person->user_id)->where('title', 'like', '%anagement meeting%')->count(), "no in-app notice for {$person->name}");
        }
        $this->assertSame(0, DB::table('app_notifications')->where('user_id', $this->emysha->user_id)->where('title', 'like', '%anagement meeting%')->count());
        $this->assertTrue(AuditLog::where('tenant_id', $this->tenant()->id)->where('action', 'like', '%eeting%')->exists(), 'the send was not logged');

        $this->runReminder(self::FRIDAY.' 15:05:00');
        $this->assertCount(1, $this->reminderRows(), 'a second run the same day sent again');

        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Http::assertNothingSent();
    }

    // ── 3. Ahmad drags his card to Done; Nurin's stays open on her board only ──

    public function test_acceptance_3_ahmad_closes_his_card_and_nurins_stays_open_on_her_board_only(): void
    {
        $this->runTasks(self::FRIDAY.' 08:00:00');
        $ahmadCard = $this->cardFor($this->ahmad);
        $nurinCard = $this->cardFor($this->nurin);

        Carbon::setTestNow(self::FRIDAY.' 16:10:00');
        $this->actingInTenantAs($this->ahmad)->postJson("/app/board/{$ahmadCard->id}/move", ['status' => 'done'])->assertOk();

        $this->assertSame('done', $ahmadCard->fresh()->status);
        $this->assertNotNull($ahmadCard->fresh()->done_at);
        $this->assertSame('todo', $nurinCard->fresh()->status, "Nurin's card moved on its own");
        $this->assertSame(self::FRIDAY, $nurinCard->fresh()->due_at?->toDateString(), 'due date moved');

        $this->actingInTenantAs($this->nurin)->get('/app/board')->assertOk()
            ->assertSee('data-id="'.$nurinCard->id.'"', false)
            ->assertDontSee('data-id="'.$ahmadCard->id.'"', false);
        $this->actingInTenantAs($this->ahmad)->get('/app/board')->assertOk()
            ->assertDontSee('data-id="'.$nurinCard->id.'"', false);

        // Nobody else may close Nurin's card for her (closed manually by the owner only).
        $this->actingInTenantAs($this->emysha)->postJson("/app/board/{$nurinCard->id}/move", ['status' => 'done'])->assertStatus(403);
        $this->assertSame('todo', $nurinCard->fresh()->status);
    }

    // ── 4. After 5 PM Nurin's card is overdue on the Director's CR-17 panel, never an award ──

    public function test_acceptance_4_after_5pm_the_open_card_is_overdue_on_the_director_panel_and_never_scores(): void
    {
        $this->runTasks(self::FRIDAY.' 08:00:00');
        $ahmadCard = $this->cardFor($this->ahmad);
        $nurinCard = $this->cardFor($this->nurin);

        Carbon::setTestNow(self::FRIDAY.' 16:10:00');
        $this->actingInTenantAs($this->ahmad)->postJson("/app/board/{$ahmadCard->id}/move", ['status' => 'done'])->assertOk();

        // Before the meeting: not overdue yet.
        Carbon::setTestNow(self::FRIDAY.' 12:00:00');
        $this->actingInTenantAs($this->director)->get('/app/dash')->assertOk()
            ->assertDontSee('data-card="'.$nurinCard->id.'"', false);

        // After 5 PM the same day: Nurin's card sits under Nurin, Ahmad's done card is gone.
        Carbon::setTestNow(self::FRIDAY.' 17:30:00');
        $page = $this->actingInTenantAs($this->director)->get('/app/dash')->assertOk();
        $page->assertSee('data-band="management"', false)
            ->assertSee('data-overdue-owner="'.$this->nurin->id.'"', false)
            ->assertSee('data-card="'.$nurinCard->id.'"', false)
            ->assertDontSee('data-card="'.$ahmadCard->id.'"', false);
        $this->assertStringContainsString('0 days overdue', $this->cardRow($page->getContent(), $nurinCard));

        // Still there the next morning, now a full day.
        Carbon::setTestNow('2026-09-12 08:00:00');
        $page = $this->actingInTenantAs($this->director)->get('/app/dash')->assertOk();
        $page->assertSee('data-card="'.$nurinCard->id.'"', false);
        $this->assertStringContainsString('1 days overdue', $this->cardRow($page->getContent(), $nurinCard));

        // The marker every award computation (CR-14a) must exclude on: system source + label.
        foreach ([$ahmadCard->fresh(), $nurinCard->fresh()] as $card) {
            $this->assertSame('management_meeting', $card->source, 'system marker missing');
            $this->assertContains('system', $card->labels ?? []);
        }
        $manual = $this->card($this->nurin, ['title' => 'Manual card', 'due_at' => self::FRIDAY]);
        $this->assertNull($manual->fresh()->source, 'a manual card must never carry the system marker');
    }

    // ── 5. Friday public holiday: task and email move to Thursday; HR can pause ──

    public function test_acceptance_5_friday_holiday_moves_the_task_and_email_to_thursday_and_hr_can_pause(): void
    {
        Mail::fake();
        Http::fake();
        PublicHoliday::create(['tenant_id' => $this->tenant()->id, 'name' => 'Malaysia Day (observed)', 'date' => '2026-09-18']);

        // Thursday 17 Sep takes the Friday's job.
        $this->runTasks('2026-09-17 08:00:00');
        $cards = $this->meetingCards('2026-09-17');
        $this->assertCount(5, $cards, 'cards not created on the Thursday before a holiday Friday');
        $this->assertSame('2026-09-17', $this->cardFor($this->nurin, '2026-09-17')->due_at?->toDateString(), 'due date must be the moved meeting day');

        $this->runReminder('2026-09-17 15:00:00');
        $this->assertCount(1, $this->reminderRows(), 'reminder not sent on the Thursday');

        // The holiday Friday itself: nothing more.
        $this->runTasks('2026-09-18 08:00:00');
        $this->runReminder('2026-09-18 15:00:00');
        $this->assertCount(5, $this->meetingCards(), 'cards created on the holiday');
        $this->assertCount(1, $this->reminderRows(), 'reminder sent on the holiday');

        // HR pauses for company-wide leave; a manager may not.
        $this->actingInTenantAs($this->ahmad)->postJson('/app/admin/management-meeting', ['paused_until' => '2026-09-30'])->assertStatus(403);
        $this->actingInTenantAs($this->hr)->postJson('/app/admin/management-meeting', ['paused_until' => '2026-09-30'])->assertSuccessful();
        $this->assertTrue(AuditLog::where('tenant_id', $this->tenant()->id)->where('action', 'like', '%eeting%')->exists(), 'settings change not audited');

        $this->runTasks('2026-09-25 08:00:00');
        $this->runReminder('2026-09-25 15:00:00');
        $this->assertCount(5, $this->meetingCards(), 'cards created while paused');
        $this->assertCount(1, $this->reminderRows(), 'reminder sent while paused');

        // Resumed: the next Friday runs as normal.
        $this->actingInTenantAs($this->hr)->postJson('/app/admin/management-meeting', ['paused_until' => null])->assertSuccessful();
        $this->runTasks('2026-10-02 08:00:00');
        $this->assertCount(5, $this->meetingCards('2026-10-02'), 'cards not created after the pause ended');

        // The meeting day is editable by the Director: Thursday meetings from now on.
        $this->actingInTenantAs($this->director)->postJson('/app/admin/management-meeting', ['meeting_day' => 4])->assertSuccessful();
        $this->runTasks('2026-10-08 08:00:00');
        $this->assertCount(5, $this->meetingCards('2026-10-08'), 'a Thursday meeting day was not honoured');
        $this->runTasks('2026-10-09 08:00:00');
        $this->assertCount(0, $this->meetingCards('2026-10-09'), 'Friday still created cards after the meeting moved');

        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    // ── 6. Track AI answers from the completed updates (DEFERRED, Track-side) ──

    public function test_acceptance_6_track_ai_answers_last_weeks_blockers_from_the_updates(): void
    {
        $this->markTestIncomplete(
            'human check: after a Friday of Track updates, ask Track AI "What were last week\'s blockers?" in Track. '
            .'Amanahku holds no Track update data and TrackPort has no read-updates method; nothing in this app can be asserted.'
        );
    }

    // ── always ─────────────────────────────────────────────────────────

    public function test_always_checks(): void
    {
        $this->assertDueDateLocked();
        $this->assertAuditLogImmutable();
        $this->assertDashboardUnchanged();
        $this->assertKeepItPlainHonoured();
    }

    // ── helpers ──────────────────────────────────────────────────────

    private function runTasks(string $at): void
    {
        Carbon::setTestNow($at);
        Artisan::call('management:meeting-tasks');
    }

    private function runReminder(string $at): void
    {
        Carbon::setTestNow($at);
        Artisan::call('management:meeting-reminder');
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, WorkItem> */
    private function meetingCards(?string $dueDate = null)
    {
        return WorkItem::where('tenant_id', $this->tenant()->id)
            ->where('source', 'management_meeting')
            ->when($dueDate !== null, fn ($q) => $q->where('source_ref', $dueDate))
            ->get();
    }

    private function cardFor(Employee $person, string $dueDate = self::FRIDAY): WorkItem
    {
        $card = $this->meetingCards($dueDate)->firstWhere('employee_id', $person->id);
        $this->assertNotNull($card, "no meeting card for {$person->name} on {$dueDate}");

        return $card;
    }

    /** @return Collection<int, object> */
    private function reminderRows()
    {
        return DB::table('port_outbox')
            ->where('port', 'mail')->where('method', 'send')
            ->where('payload', 'like', '%management_meeting_reminder%')
            ->get();
    }

    /** The slice of the management band belonging to one overdue card. */
    private function cardRow(string $html, WorkItem $card): string
    {
        $start = strpos($html, 'data-card="'.$card->id.'"');
        $this->assertNotFalse($start, "card {$card->id} not on the panel");
        $end = strpos($html, 'data-card="', $start + 12);

        return substr($html, $start, $end === false ? 1500 : $end - $start);
    }
}
