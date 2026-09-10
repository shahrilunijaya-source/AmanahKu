<?php

namespace Tests\Acceptance;

use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Project;
use App\Models\RecurringTask;
use App\Models\Timesheet;
use App\Models\TimesheetEntry;
use App\Models\WorkItem;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Acceptance for docs/specs/CR-14.md, half a: the award computation and the frozen
 * snapshot (session S17). The carousel, the Awards screen, nominations, manual awards,
 * the profile badge and the Director override are half b (S18, CR14bTest); items 2, 4
 * and 5 are pinned here only as placeholders.
 *
 * Shapes this file fixes for the generator (recorded in docs/build/OPEN.md):
 * - Two daily commands on the app clock, registered in the scheduler: `awards:freeze` at
 *   `59 23 * * *`, acting only on the last calendar day of a month (Global Clause: the
 *   snapshot is taken at 11:59 PM on the final day), and `awards:publish` at `0 8 * * *`,
 *   acting only on the first working day of a month (`App\Timesheet\DayRules::isWorkingDay`:
 *   Mon to Fri or the TOT Saturday, not a `public_holidays` row) and publishing the previous
 *   month from its snapshot. Both are idempotent: a second run the same day adds nothing.
 * - `award_snapshots`: one row per (`tenant_id`, `month` = first of the month, `award_key`,
 *   `employee_id`) with the person's `value` for that award (numeric) and `frozen_at`. A
 *   person with nothing to show for a count award has no row (or a zero row; the tests
 *   accept either). The snapshot is computed from approved data only; a change made after
 *   the freeze (an archive, a later approval) never alters it and counts in the next month.
 * - `award_results`: the winners only, one row per (`month`, `award_key`, `employee_id`)
 *   with `value`, a non-empty human `label` (the figure behind it, e.g. '2 cards before the
 *   due date'), `source` = 'auto' (manual awards, S18, use 'manual' and `reason`) and
 *   `published_at`. Ties: one row per winner. Publishing writes one audit row whose action
 *   contains 'award' and one `app_notifications` row per active employee with a user whose
 *   title contains 'award' (any case).
 * - Award keys, in the spec's list order (the order rules 9 and 10 resolve in):
 *   beating_the_traffic, never_late, always_here, clockwork_royalty, timesheet_done,
 *   billable, deadline_who, zero_overdue, chief_firefighter, done_and_dusted, not_my_task,
 *   mic_drop_mentor, question_department, walking_wikipedia, chief_hype_officer; manual
 *   (S18): main_character, office_yoda, new_but_dangerous, chosen_one.
 * - T.A.A. counting: a card is completed in the month of its first transition to done,
 *   read from the card's `audit_logs` rows (`field` status, `new_value` "done", the board
 *   move writes them through the model observer); a card created already done with no such
 *   row counts by `done_at`. Reopen and redo add nothing. Archived cards still count (the
 *   nightly sweep archives done cards). Only the Primary Owner (`employee_id`) gets
 *   completion credit (done_and_dusted, deadline_who = done strictly before `due_at`,
 *   chief_firefighter = priority high); a `helper` participant gets not_my_task credit only.
 *   Never counted anywhere: type `event`, cards with a non-null `source` (CR-34, CR-19),
 *   cards carrying the `recurring` or `system` label or a `recurring_task_occurrences` row.
 * - zero_overdue: cards owned by the person with `due_at` inside the month, none finished
 *   after its due date and none still open past it at the freeze; eligible only with at
 *   least 5 such cards; the value is that count.
 * - beating_the_traffic: the median over the month's `standard` attendance records of
 *   (clock_in minus expected_start) in minutes, lowest wins; needs at least one record.
 *   Attendance awards ignore people with no attendance record in the month.
 * - billable: approved (`timesheets.status` approved) hours on projects with a `client`,
 *   attributed to the month of the entry dates unless the sheet was decided after that
 *   month's freeze, in which case it counts in the month of `decided_at` (Global Clause).
 * - Rule 9: awards resolve in list order; once a person holds two for the month, any further
 *   award they lead passes to the runner-up (the next best value above zero), or has no
 *   winner. Rule 10: the previous month's winner of an award cannot win it again; it passes
 *   to the runner-up the same way. No data, no winner: an award with no eligible value
 *   above zero has no result row.
 */
class CR14aTest extends TestCase
{
    use AlwaysChecks;
    use RefreshDatabase;

    private const SEPTEMBER = '2026-09-01';

    private const OCTOBER = '2026-10-01';

    private Employee $director;

    private Employee $hr;

    private Employee $ahmad;

    private Employee $nurin;

    private Employee $emysha;

    private Employee $adri;

    private Project $clientProject;

    protected function setUp(): void
    {
        parent::setUp();

        $this->director = $this->person('Shahril', 'director');
        $this->hr = $this->person('Hidayah', 'hr');
        $this->ahmad = $this->person('Ahmad', 'manager');
        $this->nurin = $this->person('Nurin');
        $this->emysha = $this->person('Emysha');
        $this->adri = $this->person('Adri');

        $this->clientProject = Project::create(['tenant_id' => $this->tenant()->id, 'code' => 'KPT', 'name' => 'KPT : RMS', 'client' => 'KPT', 'pm_id' => $this->ahmad->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── 1. On 1 Oct, September's awards publish automatically from the frozen snapshot ──

    public function test_acceptance_1_september_awards_publish_on_1_october_from_the_frozen_snapshot(): void
    {
        $this->assertScheduled('awards:freeze', '59 23 * * *');
        $this->assertScheduled('awards:publish', '0 8 * * *');

        // Emysha finishes two cards before their due dates, Adri one.
        $this->finishedCard($this->emysha, '2026-09-05 10:00:00', ['due_at' => '2026-09-10']);
        $kept = $this->finishedCard($this->emysha, '2026-09-12 10:00:00', ['due_at' => '2026-09-20']);
        $this->finishedCard($this->adri, '2026-09-08 10:00:00', ['due_at' => '2026-09-15']);

        // Adri's approved September week: 10 client hours.
        $this->approvedSheet($this->adri, '2026-09-14', '2026-09-18 09:00:00', 10);

        // Not the last day, not the first working day: nothing happens.
        $this->runPublish('2026-09-30 08:00:00');
        $this->assertSame(0, DB::table('award_results')->count(), 'results published before the month ended');
        $this->runFreeze('2026-09-29 23:59:00');
        $this->assertSame(0, DB::table('award_snapshots')->count(), 'snapshot taken before the last day of the month');

        // 30 Sep 23:59: the freeze.
        $this->runFreeze('2026-09-30 23:59:00');
        $this->assertSame(2.0, $this->metric(self::SEPTEMBER, 'deadline_who', $this->emysha));
        $this->assertSame(2.0, $this->metric(self::SEPTEMBER, 'done_and_dusted', $this->emysha));
        $this->assertSame(1.0, $this->metric(self::SEPTEMBER, 'done_and_dusted', $this->adri));
        $this->assertSame(10.0, $this->metric(self::SEPTEMBER, 'billable', $this->adri));
        $this->assertEmpty($this->metric(self::SEPTEMBER, 'billable', $this->emysha), 'Emysha has no approved hours in September');
        $frozenAt = DB::table('award_snapshots')->whereDate('month', self::SEPTEMBER)->value('frozen_at');
        $this->assertNotNull($frozenAt);
        $this->assertStringStartsWith('2026-09-30 23:59', (string) $frozenAt);
        $snapshotRows = DB::table('award_snapshots')->count();

        $this->runFreeze('2026-09-30 23:59:30');
        $this->assertSame($snapshotRows, DB::table('award_snapshots')->count(), 'a second freeze the same night changed the snapshot');

        // After the freeze: the nightly sweep archives one of Emysha's done cards, and a
        // September week of Emysha's is approved on 1 Oct. Neither may touch September.
        Carbon::setTestNow('2026-10-01 02:00:00');
        $kept->update(['archived_at' => '2026-10-01 02:00:00']);
        $this->approvedSheet($this->emysha, '2026-09-21', '2026-10-01 09:00:00', 8);

        // 1 Oct (Thursday) 08:00: publish.
        $this->runPublish('2026-10-01 08:00:00');

        $this->assertWinners(self::SEPTEMBER, 'deadline_who', [$this->emysha]);
        $this->assertWinners(self::SEPTEMBER, 'done_and_dusted', [$this->emysha]);
        $this->assertWinners(self::SEPTEMBER, 'billable', [$this->adri]);
        $this->assertSame(3, $this->results(self::SEPTEMBER)->count(), 'an award with no data got a winner: '.$this->results(self::SEPTEMBER)->pluck('award_key')->implode(', '));

        $row = $this->results(self::SEPTEMBER)->firstWhere('award_key', 'done_and_dusted');
        $this->assertSame(2.0, (float) $row->value);
        $this->assertNotSame('', trim((string) $row->label), 'a result carries no figure label');
        $this->assertSame('auto', $row->source);
        $this->assertStringStartsWith('2026-10-01 08:00', (string) $row->published_at);
        $this->assertSame(2.0, $this->metric(self::SEPTEMBER, 'done_and_dusted', $this->emysha), 'the archive after the freeze changed the snapshot');

        $this->assertTrue(AuditLog::where('tenant_id', $this->tenant()->id)->where('action', 'like', '%ward%')->exists(), 'publishing wrote no audit row');
        $staff = Employee::where('tenant_id', $this->tenant()->id)->whereNotNull('user_id')->count();
        $this->assertSame($staff, DB::table('app_notifications')->where('title', 'like', '%ward%')->count(), 'not every staff member was told');

        $resultRows = DB::table('award_results')->count();
        $notices = DB::table('app_notifications')->where('title', 'like', '%ward%')->count();
        $this->runPublish('2026-10-01 08:00:30');
        $this->assertSame($resultRows, DB::table('award_results')->count(), 'a second publish the same morning duplicated results');
        $this->assertSame($notices, DB::table('app_notifications')->where('title', 'like', '%ward%')->count(), 'a second publish re-notified staff');

        // The late approval counts in October (Global Clause: after the freeze, next month).
        $this->runFreeze('2026-10-31 23:59:00');
        $this->assertSame(8.0, $this->metric(self::OCTOBER, 'billable', $this->emysha));
        $this->assertEmpty($this->metric(self::OCTOBER, 'billable', $this->adri));

        // 1 Nov is a Sunday: October publishes on Monday 2 Nov.
        $this->runPublish('2026-11-01 08:00:00');
        $this->assertSame(0, $this->results(self::OCTOBER)->count(), 'published on a Sunday');
        $this->runPublish('2026-11-02 08:00:00');
        $this->assertWinners(self::OCTOBER, 'billable', [$this->emysha]);
    }

    // ── 2. Carousel: S18 ──

    public function test_acceptance_2_carousel_one_award_per_slide_with_reactions_and_comments(): void
    {
        $this->markTestIncomplete('CR-14b (S18): the dashboard `awards` band carousel, one slide per award_results row, reactions and comments on the slide; pinned by CR14bTest');
    }

    // ── 3. Last month's winner of an award cannot win it again ──

    public function test_acceptance_3_septembers_traffic_winner_cannot_win_it_again_in_october(): void
    {
        // Emysha won CEO of Beating the Traffic for August.
        DB::table('award_results')->insert([
            'tenant_id' => $this->tenant()->id, 'month' => '2026-08-01', 'award_key' => 'beating_the_traffic',
            'employee_id' => $this->emysha->id, 'value' => -25, 'label' => '25 minutes early on a typical day',
            'source' => 'auto', 'published_at' => '2026-09-01 08:00:00', 'created_at' => '2026-09-01 08:00:00', 'updated_at' => '2026-09-01 08:00:00',
        ]);

        // September: Emysha is still the earliest (median 30 minutes early, one late day
        // keeps her off the streak awards), Adri is 10 minutes early every day.
        foreach (['2026-09-01', '2026-09-02', '2026-09-03'] as $date) {
            $this->clockIn($this->emysha, $date, '08:30:00');
            $this->clockIn($this->adri, $date, '08:50:00');
        }
        $this->clockIn($this->emysha, '2026-09-04', '09:30:00', 'late');
        $this->clockIn($this->adri, '2026-09-04', '08:50:00');

        $this->runFreeze('2026-09-30 23:59:00');
        $this->assertSame(-30.0, $this->metric(self::SEPTEMBER, 'beating_the_traffic', $this->emysha));
        $this->assertSame(-10.0, $this->metric(self::SEPTEMBER, 'beating_the_traffic', $this->adri));

        $this->runPublish('2026-10-01 08:00:00');

        $this->assertWinners(self::SEPTEMBER, 'beating_the_traffic', [$this->adri]);
        $this->assertLessThan(2, $this->results(self::SEPTEMBER)->where('employee_id', $this->emysha->id)->count(), 'the two-award cap, not rule 10, decided this');
    }

    // ── 4. Awards screen, Nominate / Select tasks: S18 ──

    public function test_acceptance_4_awards_screen_and_nominate_select_tasks(): void
    {
        $this->markTestIncomplete('CR-14b (S18): the Awards screen under The Playground, the last-Monday Nominate and Select tasks, auto-close on submission; pinned by CR14bTest');
    }

    // ── 5. Profile badge: S18 ──

    public function test_acceptance_5_winner_profile_shows_the_badge(): void
    {
        $this->markTestIncomplete('CR-14b (S18): the award badge on the winner\'s profile Wall (CR-13) and the Hall of Fame badge at three wins; pinned by CR14bTest');
    }

    // ── 6. Owner gets completion credit, helper gets helper credit only ──

    public function test_acceptance_6_owner_emysha_gets_completion_credit_helper_adri_helper_credit_only(): void
    {
        $card = $this->card($this->emysha, ['title' => 'Site survey report', 'due_at' => '2026-09-20']);
        $card->participants()->attach($this->adri->id, ['role' => 'helper']);
        $this->moveAs($this->emysha, $card, 'done', '2026-09-10 10:00:00');

        $this->runFreeze('2026-09-30 23:59:00');

        $this->assertSame(1.0, $this->metric(self::SEPTEMBER, 'done_and_dusted', $this->emysha));
        $this->assertSame(1.0, $this->metric(self::SEPTEMBER, 'deadline_who', $this->emysha));
        $this->assertEmpty($this->metric(self::SEPTEMBER, 'not_my_task', $this->emysha), 'the owner got helper credit');
        $this->assertSame(1.0, $this->metric(self::SEPTEMBER, 'not_my_task', $this->adri));
        $this->assertEmpty($this->metric(self::SEPTEMBER, 'done_and_dusted', $this->adri), 'the helper got completion credit');
        $this->assertEmpty($this->metric(self::SEPTEMBER, 'deadline_who', $this->adri), 'the helper got completion credit');

        $this->runPublish('2026-10-01 08:00:00');

        $this->assertWinners(self::SEPTEMBER, 'done_and_dusted', [$this->emysha]);
        $this->assertWinners(self::SEPTEMBER, 'deadline_who', [$this->emysha]);
        $this->assertWinners(self::SEPTEMBER, 'not_my_task', [$this->adri]);
        $this->assertSame(3, $this->results(self::SEPTEMBER)->count());
    }

    // ── 7. Done, reopened, done again: counted once ──

    public function test_acceptance_7_done_reopen_done_counts_once(): void
    {
        $card = $this->card($this->emysha, ['title' => 'Bounced card']);
        $this->moveAs($this->emysha, $card, 'done', '2026-09-10 10:00:00');
        $this->moveAs($this->emysha, $card, 'todo', '2026-09-12 10:00:00');
        $this->moveAs($this->emysha, $card, 'done', '2026-09-15 10:00:00');

        // First finished in August, redone in September: nothing for September.
        $august = $this->card($this->emysha, ['title' => 'August card']);
        $this->moveAs($this->emysha, $august, 'done', '2026-08-20 10:00:00');
        $this->moveAs($this->emysha, $august, 'todo', '2026-09-02 10:00:00');
        $this->moveAs($this->emysha, $august, 'done', '2026-09-03 10:00:00');

        $this->runFreeze('2026-09-30 23:59:00');
        $this->assertSame(1.0, $this->metric(self::SEPTEMBER, 'done_and_dusted', $this->emysha), 'reopen/redo added a completion');

        $this->runPublish('2026-10-01 08:00:00');
        $this->assertWinners(self::SEPTEMBER, 'done_and_dusted', [$this->emysha]);
        $this->assertSame(1.0, (float) $this->results(self::SEPTEMBER)->firstWhere('award_key', 'done_and_dusted')->value);
    }

    // ── 8. Event cards and system-generated cards never count ──

    public function test_acceptance_8_event_cards_and_recurring_tasks_never_count(): void
    {
        $special = ['priority' => 'high', 'due_at' => '2026-09-30', 'status' => 'done', 'done_at' => '2026-09-10 10:00:00'];

        $this->card($this->emysha, $special + ['title' => 'Town hall', 'type' => 'event']);
        $recurring = $this->card($this->emysha, $special + ['title' => 'Monthly report', 'labels' => ['recurring']]);
        $schedule = RecurringTask::create(['tenant_id' => $this->tenant()->id, 'title' => 'Monthly report', 'frequency' => 'monthly', 'interval' => 1, 'start_on' => '2026-09-01', 'priority' => 'high', 'lead_days' => 0, 'min_attended' => 0]);
        DB::table('recurring_task_occurrences')->insert(['tenant_id' => $this->tenant()->id, 'recurring_task_id' => $schedule->id, 'period' => '2026-09-01', 'work_item_id' => $recurring->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->card($this->emysha, $special + ['title' => 'Update Track for management meeting', 'labels' => ['system'], 'source' => 'management_meeting', 'source_ref' => '2026-09-11']);
        $this->card($this->emysha, $special + ['title' => 'Auto-closed by CR-19', 'labels' => ['system'], 'source' => 'auto_done', 'source_ref' => '2026-09-12']);

        // One ordinary card, low priority, no due date.
        $plain = $this->card($this->emysha, ['title' => 'Real work']);
        $this->moveAs($this->emysha, $plain, 'done', '2026-09-11 10:00:00');

        $this->runFreeze('2026-09-30 23:59:00');

        $this->assertSame(1.0, $this->metric(self::SEPTEMBER, 'done_and_dusted', $this->emysha), 'a system card counted as a completion');
        $this->assertEmpty($this->metric(self::SEPTEMBER, 'chief_firefighter', $this->emysha), 'a system card counted as a high-priority fix');
        $this->assertEmpty($this->metric(self::SEPTEMBER, 'deadline_who', $this->emysha), 'a system card counted as beating a deadline');
        $this->assertEmpty($this->metric(self::SEPTEMBER, 'zero_overdue', $this->emysha), 'system cards counted towards the assigned-card floor');

        $this->runPublish('2026-10-01 08:00:00');
        $this->assertWinners(self::SEPTEMBER, 'done_and_dusted', [$this->emysha]);
        $this->assertSame(1, $this->results(self::SEPTEMBER)->count(), 'a system card produced a winner: '.$this->results(self::SEPTEMBER)->pluck('award_key')->implode(', '));
    }

    // ── 9. Zero overdue needs at least five assigned cards ──

    public function test_acceptance_9_three_cards_and_zero_overdue_is_not_eligible(): void
    {
        foreach ([1, 2, 3] as $i) {
            $this->finishedCard($this->nurin, "2026-09-0{$i} 10:00:00", ['due_at' => '2026-09-25']);
        }
        foreach ([1, 2, 3, 4, 5] as $i) {
            $this->finishedCard($this->ahmad, "2026-09-0{$i} 10:00:00", ['due_at' => '2026-09-25']);
        }

        $this->runFreeze('2026-09-30 23:59:00');
        $this->assertSame(5.0, $this->metric(self::SEPTEMBER, 'zero_overdue', $this->ahmad));
        $nurin = $this->metric(self::SEPTEMBER, 'zero_overdue', $this->nurin);
        $this->assertTrue($nurin === null || $nurin < 5.0, 'three cards made Nurin eligible');

        $this->runPublish('2026-10-01 08:00:00');
        $this->assertWinners(self::SEPTEMBER, 'zero_overdue', [$this->ahmad]);
    }

    // ── 10. Leading in three awards wins two, the third goes to the runner-up ──

    public function test_acceptance_10_leading_three_awards_wins_two_third_goes_to_runner_up(): void
    {
        foreach ([1, 2, 3] as $i) {
            $this->finishedCard($this->ahmad, "2026-09-1{$i} 10:00:00", ['priority' => 'high', 'due_at' => '2026-09-30']);
        }
        foreach ([1, 2] as $i) {
            $this->finishedCard($this->nurin, "2026-09-1{$i} 10:00:00", ['priority' => 'high', 'due_at' => '2026-09-30']);
        }

        $this->runFreeze('2026-09-30 23:59:00');
        foreach (['deadline_who', 'chief_firefighter', 'done_and_dusted'] as $key) {
            $this->assertSame(3.0, $this->metric(self::SEPTEMBER, $key, $this->ahmad), $key);
            $this->assertSame(2.0, $this->metric(self::SEPTEMBER, $key, $this->nurin), $key);
        }

        $this->runPublish('2026-10-01 08:00:00');

        // List order: deadline_who (7th) and chief_firefighter (9th) are Ahmad's two;
        // done_and_dusted (10th) passes to Nurin.
        $this->assertWinners(self::SEPTEMBER, 'deadline_who', [$this->ahmad]);
        $this->assertWinners(self::SEPTEMBER, 'chief_firefighter', [$this->ahmad]);
        $this->assertWinners(self::SEPTEMBER, 'done_and_dusted', [$this->nurin]);
        $this->assertSame(2, $this->results(self::SEPTEMBER)->where('employee_id', $this->ahmad->id)->count(), 'Ahmad holds more than two awards');
        $this->assertSame(3, $this->results(self::SEPTEMBER)->count(), 'an award with no data got a winner: '.$this->results(self::SEPTEMBER)->pluck('award_key')->implode(', '));
    }

    // ── Always ──

    public function test_always_checks(): void
    {
        $this->assertDueDateLocked();
        $this->assertAuditLogImmutable();
        $this->assertDashboardUnchanged();
        $this->assertKeepItPlainHonoured();
    }

    // ── Helpers ──

    private function assertScheduled(string $command, string $expression): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_contains($e->command ?? '', $command));
        $this->assertCount(1, $events, "{$command} is not in the scheduler");
        $this->assertStringStartsWith($expression, $events->first()->expression, "{$command} is not scheduled at {$expression}");
    }

    private function runFreeze(string $at): void
    {
        Carbon::setTestNow($at);
        Artisan::call('awards:freeze');
    }

    private function runPublish(string $at): void
    {
        Carbon::setTestNow($at);
        Artisan::call('awards:publish');
    }

    /** A card created todo and moved to done through the board by its owner at $doneAt. */
    private function finishedCard(Employee $owner, string $doneAt, array $attrs = []): WorkItem
    {
        Carbon::setTestNow(Carbon::parse($doneAt)->subDay());
        $card = $this->card($owner, $attrs + ['title' => 'Card for '.$owner->name]);
        $this->moveAs($owner, $card, 'done', $doneAt);

        return $card->fresh();
    }

    private function moveAs(Employee $actor, WorkItem $card, string $status, string $at): void
    {
        Carbon::setTestNow($at);
        $this->actingInTenantAs($actor)
            ->postJson("/app/board/{$card->id}/move", ['status' => $status])
            ->assertOk();
        $this->assertSame($status, $card->fresh()->status);
    }

    private function approvedSheet(Employee $employee, string $weekStart, string $decidedAt, float $hours): Timesheet
    {
        $sheet = Timesheet::create([
            'tenant_id' => $this->tenant()->id, 'employee_id' => $employee->id, 'week_start' => $weekStart,
            'status' => 'approved', 'total_hours' => $hours, 'submitted_at' => $weekStart.' 18:00:00',
            'decided_at' => $decidedAt, 'decided_by_id' => $this->ahmad->id,
        ]);
        TimesheetEntry::create([
            'tenant_id' => $this->tenant()->id, 'timesheet_id' => $sheet->id, 'entry_date' => $weekStart,
            'project_id' => $this->clientProject->id, 'project' => $this->clientProject->name,
            'percentage' => 100, 'hours' => $hours, 'description' => 'Client work',
        ]);

        return $sheet;
    }

    private function clockIn(Employee $employee, string $date, string $clockIn, string $status = 'on_time'): AttendanceRecord
    {
        return AttendanceRecord::create([
            'tenant_id' => $this->tenant()->id, 'employee_id' => $employee->id, 'date' => $date,
            'clock_in' => $clockIn, 'clock_out' => '18:00:00', 'expected_start' => '09:00:00', 'expected_end' => '18:00:00',
            'status' => $status, 'type' => 'standard', 'flags' => $status === 'late' ? ['late'] : [],
        ]);
    }

    /** The frozen value for one person and award, null when there is no row. */
    private function metric(string $month, string $awardKey, Employee $employee): ?float
    {
        $value = DB::table('award_snapshots')->where('tenant_id', $this->tenant()->id)
            ->whereDate('month', $month)->where('award_key', $awardKey)->where('employee_id', $employee->id)
            ->value('value');

        return $value === null ? null : (float) $value;
    }

    private function results(string $month): Collection
    {
        return DB::table('award_results')->where('tenant_id', $this->tenant()->id)->whereDate('month', $month)->get();
    }

    /** @param  list<Employee>  $winners */
    private function assertWinners(string $month, string $awardKey, array $winners): void
    {
        $expected = collect($winners)->pluck('id')->sort()->values()->all();
        $actual = $this->results($month)->where('award_key', $awardKey)->pluck('employee_id')->map(fn ($id) => (int) $id)->sort()->values()->all();

        $this->assertSame($expected, $actual, "{$awardKey} winners for {$month}");
    }
}
