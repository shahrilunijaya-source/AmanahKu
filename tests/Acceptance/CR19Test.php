<?php

namespace Tests\Acceptance;

use App\Models\AuditLog;
use App\Models\CompanyEvent;
use App\Models\Employee;
use App\Models\WorkItem;
use App\Support\ManagementExceptions;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Acceptance for docs/specs/CR-19.md (session S19, auto-Done rules for system-generated
 * T.A.A. cards). Shapes fixed in OPEN "QA / CR-19 / shapes fixed by CR19Test":
 *
 * - Scheduler: `board:auto-done`, registered `*\/15 * * * *`, ships FLAGGED OFF
 *   (`config('services.auto_done.enabled')`, env `AMANAHKU_AUTO_DONE`, default false). With
 *   the flag off the command changes nothing and prints a dry-run line naming what it
 *   would have closed ("dry-run" in the output). Triggering actions (attendance marked,
 *   nomination submitted, winners published, invitation withdrawn) close their card at
 *   once regardless of the flag, as they already do for awards.
 * - Auto-close marker: `work_items.auto_closed_at` (nullable datetime). Every auto-close
 *   writes one `work_item_comments` row with `employee_id` null and body
 *   "Closed automatically – <reason>" (en dash), an audit row on the card's `status`
 *   (source 'system' or 'sync job' when the scheduler did it), and the card shows an "Auto"
 *   badge: `GET /app/board/{card}` JSON carries `auto_closed: true`, the board HTML carries
 *   `data-auto-closed="1"` on the card.
 * - Reopen: `POST /app/board/{card}/move {status: todo}` on an auto-closed card clears
 *   `auto_closed_at`; it is a normal manual card from then on.
 * - Event attendee cards (CR-11): once the event's `ends_at` has passed and the RSVP is
 *   still going/registered/maybe, the card stays open and shows "Pending Attendance"
 *   (board HTML and JSON `pending_attendance: true`); the scheduler never closes it by
 *   time, it only notifies the organiser once (`app_notifications` dedupe key
 *   `event-attendance-<event id>` to the creator's user). Marking `attended` closes the
 *   card Done; marking the new RSVP response `did_not_attend` archives it (archived_at set,
 *   status not done); dropping the person from the attendee list cancels it
 *   (`cancelled_at` and `archived_at` set).
 * - Awards tasks (CR-14b): a nominate card closes Done on submission (already), and
 *   `board:auto-done` archives a still-open nominate card once its month has ended
 *   (`archived_at` set, not done); a select card closes Done when `awards:publish` runs.
 * - Awards exclusion: `Awards::cards()` rejects every card with `auto_closed_at` set,
 *   on top of the existing type/source/label exclusions.
 * - Overdue counts (CR-17 `ManagementExceptions::overdue()`) never list an auto-closed card.
 * - Manual cards (null `source`, no `system`/`recurring` label, not an event) are never
 *   touched by the scheduler, however overdue.
 * - The Google Calendar row is DEFERRED (inbound sync, no port yet): human check.
 */
class CR19Test extends TestCase
{
    use AlwaysChecks;
    use RefreshDatabase;

    private const TITLE = 'Exclusive Invitation: HPE AI Developer Days';

    private const SEPTEMBER = '2026-09-01';

    private Employee $hr;

    private Employee $kussairi;

    private Employee $syakir;

    private Employee $staff;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-08-20 10:00:00');

        $this->hr = $this->person('Hidayah', 'hr');
        $this->kussairi = $this->person('Kussairi', 'manager');
        $this->syakir = $this->person('Syakir');
        $this->staff = $this->person('Shazwan');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── 1. event ends: Pending Attendance, then Attended → Done (Auto), Did Not Attend → Archived ──

    public function test_acceptance_1_after_the_event_cards_wait_for_attendance_then_close_by_what_was_recorded(): void
    {
        $this->assertScheduled('board:auto-done', '*/15 * * * *');
        $this->assertFalse((bool) config('services.auto_done.enabled'), 'the auto-Done scheduler must ship flagged off');

        $event = $this->hpeEvent();
        $this->setAttendees($event, [$this->kussairi->id, $this->syakir->id, $this->staff->id]);
        $syakirCard = $this->eventCard($event, $this->syakir);
        $kussairiCard = $this->eventCard($event, $this->kussairi);
        $staffCard = $this->eventCard($event, $this->staff);

        // Before the end: nothing pending, nothing closed.
        Carbon::setTestNow('2026-08-27 15:00:00');
        $this->actingInTenantAs($this->syakir)->get('/app/board')->assertOk()->assertDontSee('Pending Attendance');
        $this->assertFalse($this->show($this->syakir, $syakirCard)['pending_attendance'] ?? false);

        // After the end, with the scheduler live: the cards stay open and say Pending Attendance.
        Carbon::setTestNow('2026-08-27 16:00:00');
        $this->runAutoDone(live: true);
        foreach ([$syakirCard, $kussairiCard, $staffCard] as $card) {
            $card->refresh();
            $this->assertSame('todo', $card->status, 'an attendee card was closed by the event ending');
            $this->assertNull($card->archived_at);
            $this->assertNull($card->auto_closed_at);
        }
        $this->actingInTenantAs($this->syakir)->get('/app/board')->assertOk()->assertSee('Pending Attendance');
        $this->assertTrue($this->show($this->syakir, $syakirCard)['pending_attendance']);

        // The organiser is prompted once to record attendance.
        $prompt = DB::table('app_notifications')->where('user_id', $this->hr->user_id)->where('dedupe_key', 'event-attendance-'.$event->id);
        $this->assertSame(1, $prompt->count(), 'the organiser was not prompted to record attendance');
        $this->runAutoDone(live: true);
        $this->assertSame(1, $prompt->count(), 'the organiser was prompted twice');

        // Syakir attended: his card closes Done with the Auto badge and the activity line.
        Carbon::setTestNow('2026-08-28 09:00:00');
        $this->actingInTenantAs($this->hr)
            ->post("/app/events/{$event->id}/rsvp", ['response' => 'attended', 'employee_id' => $this->syakir->id])
            ->assertSessionHasNoErrors();
        $syakirCard->refresh();
        $this->assertSame('done', $syakirCard->status);
        $this->assertNotNull($syakirCard->done_at);
        $this->assertNotNull($syakirCard->auto_closed_at);
        $this->assertNull($syakirCard->archived_at);
        $this->assertAutoClosedTrail($syakirCard, 'Attended');
        $this->assertTrue($this->show($this->syakir, $syakirCard)['auto_closed']);
        $this->actingInTenantAs($this->syakir)->get('/app/board')->assertOk()->assertSee('data-auto-closed="1"', false);

        // Kussairi did not attend: archived, not Done.
        $this->actingInTenantAs($this->hr)
            ->post("/app/events/{$event->id}/rsvp", ['response' => 'did_not_attend', 'employee_id' => $this->kussairi->id])
            ->assertSessionHasNoErrors();
        $kussairiCard->refresh();
        $this->assertNotNull($kussairiCard->archived_at);
        $this->assertNotSame('done', $kussairiCard->status, 'a no-show was counted as Done');
        $this->assertNull($kussairiCard->cancelled_at);
        $this->assertNotNull($kussairiCard->auto_closed_at);
        $this->assertAutoClosedTrail($kussairiCard, 'Did not attend');
        $this->actingInTenantAs($this->kussairi)->get('/app/board')->assertOk()->assertDontSee(self::TITLE);
        $this->actingInTenantAs($this->hr)->get("/app/events/{$event->id}")->assertOk()->assertSeeInOrder(['Kussairi', 'Did not attend']);

        // Shazwan's invitation is withdrawn: cancelled, never Done.
        $this->setAttendees($event, [$this->kussairi->id, $this->syakir->id]);
        $staffCard->refresh();
        $this->assertNotNull($staffCard->cancelled_at);
        $this->assertNotNull($staffCard->archived_at);
        $this->assertNotSame('done', $staffCard->status);
        $this->assertAutoClosedTrail($staffCard, 'withdrawn');

        // Reopening Syakir's card makes it a normal card again.
        $this->actingInTenantAs($this->syakir)->postJson("/app/board/{$syakirCard->id}/move", ['status' => 'todo'])->assertOk();
        $syakirCard->refresh();
        $this->assertSame('todo', $syakirCard->status);
        $this->assertNull($syakirCard->auto_closed_at, 'a reopened card still carries the auto marker');
        $this->assertFalse($this->show($this->syakir, $syakirCard)['auto_closed']);
        Carbon::setTestNow('2026-09-05 09:00:00');
        $this->runAutoDone(live: true);
        $this->assertSame('todo', $syakirCard->fresh()->status, 'a reopened card was auto-closed again');
    }

    // ── 2. Google Calendar deletion → Cancelled (calendar). DEFERRED ──

    public function test_acceptance_2_a_meeting_deleted_in_google_calendar_shows_cancelled_calendar_not_archived(): void
    {
        $this->markTestIncomplete(
            'human check (DEFERRED, docs/build/RULES.md): the Calendar pull has no port yet. '
            .'Once it exists, delete a synced meeting in Google Calendar and confirm the card reads '
            .'"Cancelled (calendar)" with cancelled_at set, archived_at null, history intact.'
        );
    }

    // ── 3. nominate task leaves To Do on submission; window close archives; select closes on publish ──

    public function test_acceptance_3_submitting_a_nomination_takes_the_nominate_task_off_to_do(): void
    {
        Carbon::setTestNow('2026-09-28 08:00:00');
        Artisan::call('awards:tasks');
        $mine = WorkItem::where('employee_id', $this->syakir->id)->where('source', 'awards')->where('source_ref', '2026-09-nominate')->firstOrFail();
        $theirs = WorkItem::where('employee_id', $this->staff->id)->where('source', 'awards')->where('source_ref', '2026-09-nominate')->firstOrFail();
        $select = WorkItem::where('employee_id', $this->kussairi->id)->where('source', 'awards')->where('source_ref', '2026-09-select')->firstOrFail();
        $this->actingInTenantAs($this->syakir)->get('/app/board')->assertOk()->assertSee($mine->title);

        $this->actingInTenantAs($this->syakir)
            ->postJson('/app/awards/nominate', ['award_key' => 'main_character', 'employee_id' => $this->staff->id, 'reason' => 'Carried the go-live weekend'])
            ->assertOk();

        $mine->refresh();
        $this->assertSame('done', $mine->status);
        $this->assertNotNull($mine->auto_closed_at);
        $this->assertAutoClosedTrail($mine, 'Nomination submitted');
        $this->assertTrue($this->show($this->syakir, $mine)['auto_closed']);
        $board = $this->actingInTenantAs($this->syakir)->get('/app/board')->assertOk();
        $this->assertStringNotContainsString($mine->title, $this->column($board->getContent(), 'todo'), 'the nominate task is still in To Do');
        $this->assertSame('todo', $theirs->fresh()->status, 'someone else\'s nominate task was closed');

        // Flag off: the scheduler only reports what it would do.
        Carbon::setTestNow('2026-10-01 00:15:00');
        $output = $this->runAutoDone(live: false);
        $this->assertStringContainsString('dry-run', strtolower($output));
        $this->assertSame('todo', $theirs->fresh()->status, 'the flagged-off scheduler closed a card');
        $this->assertNull($theirs->fresh()->archived_at);

        // Flag on: the window closed without a submission, so the task is archived, not Done.
        $this->runAutoDone(live: true);
        $theirs->refresh();
        $this->assertNotNull($theirs->archived_at);
        $this->assertNotSame('done', $theirs->status, 'an unsubmitted nominate task was counted as Done');
        $this->assertNotNull($theirs->auto_closed_at);
        $this->assertAutoClosedTrail($theirs, 'window closed');
        $this->assertSame('todo', $select->fresh()->status, 'the select task was closed before publish');

        // Winners published: the select task closes Done.
        Carbon::setTestNow('2026-10-01 08:00:00');
        Artisan::call('awards:publish');
        $select->refresh();
        $this->assertSame('done', $select->status);
        $this->assertNotNull($select->auto_closed_at);
        $this->assertAutoClosedTrail($select, 'published');
    }

    // ── 4. auto-closed cards never score ──

    public function test_acceptance_4_auto_closed_cards_do_not_count_toward_done_and_dusted_or_overdue(): void
    {
        Carbon::setTestNow('2026-09-10 09:00:00');
        $manual = $this->card($this->syakir, ['title' => 'Real work', 'due_at' => '2026-09-20', 'status' => 'done', 'done_at' => '2026-09-10 09:00:00']);
        $this->card($this->syakir, ['title' => 'Nominate this month', 'due_at' => '2026-09-30', 'status' => 'done', 'done_at' => '2026-09-11 09:00:00', 'source' => 'awards', 'source_ref' => '2026-09-nominate', 'labels' => ['system'], 'auto_closed_at' => '2026-09-11 09:00:00']);
        $this->card($this->syakir, ['title' => 'Reopened then auto again', 'due_at' => '2026-09-12', 'status' => 'done', 'done_at' => '2026-09-12 09:00:00', 'auto_closed_at' => '2026-09-12 09:00:00']);
        $event = $this->hpeEvent(['event_date' => '2026-09-03', 'starts_at' => '2026-09-03 09:00', 'ends_at' => '2026-09-03 15:45']);
        $this->setAttendees($event, [$this->syakir->id]);
        Carbon::setTestNow('2026-09-04 09:00:00');
        $this->actingInTenantAs($this->hr)->post("/app/events/{$event->id}/rsvp", ['response' => 'attended', 'employee_id' => $this->syakir->id])->assertSessionHasNoErrors();
        $this->assertNotNull($this->eventCard($event, $this->syakir)->fresh()->auto_closed_at);

        Carbon::setTestNow('2026-09-30 23:59:00');
        Artisan::call('awards:freeze');
        $rows = DB::table('award_snapshots')->where('month', self::SEPTEMBER)->where('employee_id', $this->syakir->id);
        $this->assertSame(1.0, (float) $rows->clone()->where('award_key', 'done_and_dusted')->value('value'), 'auto-closed cards counted toward Done & Dusted');
        $this->assertSame(1.0, (float) $rows->clone()->where('award_key', 'deadline_who')->value('value'), 'auto-closed cards counted toward Deadline Who?');

        // Overdue: an auto-archived (never Done) system card is not overdue on the Director's panel.
        Carbon::setTestNow('2026-10-05 09:00:00');
        $this->card($this->syakir, ['title' => 'Unsubmitted nominate', 'due_at' => '2026-09-30', 'status' => 'todo', 'source' => 'awards', 'source_ref' => '2026-09-nominate', 'labels' => ['system'], 'auto_closed_at' => '2026-10-01 00:15:00', 'archived_at' => '2026-10-01 00:15:00']);
        $late = $this->card($this->syakir, ['title' => 'Genuinely late', 'due_at' => '2026-09-30', 'status' => 'todo']);
        $groups = app(ManagementExceptions::class)->overdue(null);
        $titles = collect($groups)->flatMap(fn ($g) => collect($g['cards'])->pluck('title'))->all();
        $this->assertContains('Genuinely late', $titles);
        $this->assertNotContains('Unsubmitted nominate', $titles, 'an auto-closed card is listed as overdue');
        $this->assertSame('done', $manual->fresh()->status);
        $this->assertSame('todo', $late->fresh()->status);
    }

    // ── 5. manual cards are never auto-closed by date ──

    public function test_acceptance_5_a_normal_overdue_task_stays_open_until_someone_moves_it(): void
    {
        Carbon::setTestNow('2026-09-01 09:00:00');
        $late = $this->card($this->staff, ['title' => 'Write the RMS handover', 'due_at' => '2026-09-03', 'status' => 'prog']);
        $labelled = $this->card($this->staff, ['title' => 'Old recurring note', 'due_at' => '2026-09-03', 'status' => 'todo', 'labels' => ['recurring']]);

        Carbon::setTestNow('2026-09-20 09:00:00');
        $this->runAutoDone(live: true);
        $this->runAutoDone(live: true);

        foreach ([$late, $labelled] as $card) {
            $card->refresh();
            $this->assertNotSame('done', $card->status, "{$card->title} was auto-closed");
            $this->assertNull($card->archived_at);
            $this->assertNull($card->cancelled_at);
            $this->assertNull($card->auto_closed_at);
        }
        $this->assertSame(0, DB::table('work_item_comments')->where('body', 'like', 'Closed automatically%')->count());
        $this->actingInTenantAs($this->staff)->get('/app/board')->assertOk()->assertSee('Write the RMS handover');

        // Only a person moves it.
        $this->actingInTenantAs($this->staff)->postJson("/app/board/{$late->id}/move", ['status' => 'done'])->assertOk();
        $late->refresh();
        $this->assertSame('done', $late->status);
        $this->assertNull($late->auto_closed_at);
        $this->assertFalse($this->show($this->staff, $late)['auto_closed']);
    }

    // ── always ──

    public function test_always_checks(): void
    {
        $this->assertDueDateLocked();
        $this->assertAuditLogImmutable();
        $this->assertDashboardUnchanged();
        $this->assertKeepItPlainHonoured();
    }

    // ── helpers ──

    private function runAutoDone(bool $live): string
    {
        config(['services.auto_done.enabled' => $live]);
        Artisan::call('board:auto-done');

        return Artisan::output();
    }

    private function assertScheduled(string $command, string $expression): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains($e->command ?? '', $command));
        $this->assertTrue($events->isNotEmpty(), "{$command} is not in the scheduler");
        $this->assertSame($expression, $events->first()->expression, "{$command} schedule expression");
    }

    /** The activity line, the audit row and the Auto marker every auto-close must leave. */
    private function assertAutoClosedTrail(WorkItem $card, string $reasonFragment): void
    {
        $line = DB::table('work_item_comments')->where('work_item_id', $card->id)->where('body', 'like', 'Closed automatically – %')->orderByDesc('id')->first();
        $this->assertNotNull($line, "{$card->title} has no 'Closed automatically – <reason>' activity line");
        $this->assertNull($line->employee_id, 'the auto-close line is attributed to a person');
        $this->assertStringContainsStringIgnoringCase($reasonFragment, $line->body);
        $this->assertTrue(
            AuditLog::where('tenant_id', $this->tenant()->id)->where('subject_type', WorkItem::class)->where('subject_id', $card->id)
                ->whereIn('field', ['status', 'archived_at', 'cancelled_at'])->exists(),
            "{$card->title} auto-closed without an audit row"
        );
    }

    /** @return array<string, mixed> */
    private function show(Employee $viewer, WorkItem $card): array
    {
        return $this->actingInTenantAs($viewer)->getJson("/app/board/{$card->id}")->assertOk()->json();
    }

    /** The HTML of one board column (`data-list="<status>"` wrapper). */
    private function column(string $html, string $status): string
    {
        $parts = preg_split('/data-list="/', $html);
        foreach ($parts as $part) {
            if (str_starts_with($part, $status.'"')) {
                return $part;
            }
        }
        $this->fail("no column data-list=\"{$status}\" on the board");
    }

    private function eventCard(CompanyEvent $event, Employee $owner): WorkItem
    {
        return WorkItem::withoutGlobalScopes()->where('company_event_id', $event->id)->where('employee_id', $owner->id)->firstOrFail();
    }

    private function eventFields(array $overrides = []): array
    {
        return array_merge([
            'title' => self::TITLE,
            'type' => 'training',
            'event_date' => '2026-08-27',
            'starts_at' => '2026-08-27 09:00',
            'ends_at' => '2026-08-27 15:45',
            'location' => 'HPE Malaysia, Level 23A, Menara Suezcap 2, Kuala Lumpur',
            'host' => 'HPE',
            'registration_url' => 'https://developer.hpe.com/ai-days-2026/register',
            'description' => 'A full day on agentic AI with hands-on labs.',
        ], $overrides);
    }

    private function hpeEvent(array $overrides = []): CompanyEvent
    {
        $this->actingInTenantAs($this->hr)->post('/app/events', $this->eventFields($overrides))->assertSessionHasNoErrors();

        return CompanyEvent::query()->where('title', self::TITLE)->orderByDesc('id')->firstOrFail();
    }

    /** @param list<int> $ids */
    private function setAttendees(CompanyEvent $event, array $ids): void
    {
        $this->actingInTenantAs($this->hr)->postJson("/app/events/{$event->id}/attendees", ['attendees' => $ids])->assertSuccessful();
    }
}
