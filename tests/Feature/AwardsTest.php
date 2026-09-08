<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\BirthdayWish;
use App\Models\BirthdayWishReaction;
use App\Models\Employee;
use App\Models\KnowledgeEntry;
use App\Models\KnowledgeSegment;
use App\Models\PublicHoliday;
use App\Models\Tenant;
use App\Models\Timesheet;
use App\Models\TimesheetDay;
use App\Models\TotComment;
use App\Models\TotReaction;
use App\Models\TotSession;
use App\Models\User;
use App\Timesheet\DayRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Covers the eight award keys `CR14aTest` never drives (never_late, always_here,
 * clockwork_royalty, timesheet_done, chief_hype_officer, walking_wikipedia,
 * question_department, mic_drop_mentor), a tie between two winners, a holiday landing on
 * the 1st of the month pushing publish to the 2nd, and cross-tenant isolation.
 */
class AwardsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function tenant(string $slug = 'acme'): Tenant
    {
        return Tenant::firstOrCreate(['slug' => $slug], ['name' => 'Acme', 'initials' => 'AC']);
    }

    private function person(Tenant $tenant, string $name, array $attrs = []): Employee
    {
        $user = User::create(['name' => $name, 'email' => strtolower(preg_replace('/\W+/', '', $name)).uniqid().'@example.com', 'password' => Hash::make('password')]);
        $user->tenants()->attach($tenant->id, ['role' => 'employee']);

        return Employee::create(array_merge(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'name' => $name, 'status' => 'active', 'workload' => 'green'], $attrs));
    }

    /** @return list<string> ISO dates */
    private function workingDaysIn(string $month): array
    {
        $rules = app(DayRules::class);
        $start = Carbon::parse($month)->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $days = [];
        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            if ($rules->isWorkingDay($day)) {
                $days[] = $day->toDateString();
            }
        }

        return $days;
    }

    private function freeze(string $at): void
    {
        Carbon::setTestNow($at);
        $this->artisan('awards:freeze')->run();
    }

    private function publish(string $at): void
    {
        Carbon::setTestNow($at);
        $this->artisan('awards:publish')->run();
    }

    private function metric(Tenant $tenant, string $month, string $key, Employee $employee): ?float
    {
        $value = DB::table('award_snapshots')->where('tenant_id', $tenant->id)
            ->whereDate('month', $month)->where('award_key', $key)->where('employee_id', $employee->id)->value('value');

        return $value === null ? null : (float) $value;
    }

    #[Test]
    public function never_late_needs_no_late_day_and_always_here_needs_perfect_attendance(): void
    {
        $tenant = $this->tenant();
        $full = $this->person($tenant, 'Full Attendance');
        $oneLate = $this->person($tenant, 'One Late Day');
        $missedADay = $this->person($tenant, 'Missed A Day');

        $days = $this->workingDaysIn('2026-11-01');
        foreach ($days as $i => $date) {
            $status = 'on_time';
            AttendanceRecord::create(['tenant_id' => $tenant->id, 'employee_id' => $full->id, 'date' => $date, 'clock_in' => '09:00:00', 'clock_out' => '18:00:00', 'expected_start' => '09:00:00', 'expected_end' => '18:00:00', 'status' => $status, 'type' => 'standard', 'flags' => []]);

            $lateThisDay = $i === 0 ? 'late' : 'on_time';
            AttendanceRecord::create(['tenant_id' => $tenant->id, 'employee_id' => $oneLate->id, 'date' => $date, 'clock_in' => $lateThisDay === 'late' ? '09:30:00' : '09:00:00', 'clock_out' => '18:00:00', 'expected_start' => '09:00:00', 'expected_end' => '18:00:00', 'status' => $lateThisDay, 'type' => 'standard', 'flags' => $lateThisDay === 'late' ? ['late'] : []]);

            if ($i > 0) {
                AttendanceRecord::create(['tenant_id' => $tenant->id, 'employee_id' => $missedADay->id, 'date' => $date, 'clock_in' => '09:00:00', 'clock_out' => '18:00:00', 'expected_start' => '09:00:00', 'expected_end' => '18:00:00', 'status' => 'on_time', 'type' => 'standard', 'flags' => []]);
            }
        }

        $this->freeze('2026-11-30 23:59:00');

        $this->assertSame((float) count($days), $this->metric($tenant, '2026-11-01', 'never_late', $full));
        $this->assertSame((float) count($days), $this->metric($tenant, '2026-11-01', 'always_here', $full));
        $this->assertNull($this->metric($tenant, '2026-11-01', 'never_late', $oneLate), 'one late day still counted as never late');
        $this->assertNull($this->metric($tenant, '2026-11-01', 'always_here', $missedADay), 'a missed day still counted as full attendance');
    }

    #[Test]
    public function clockwork_royalty_is_the_longest_on_time_streak(): void
    {
        $tenant = $this->tenant();
        $employee = $this->person($tenant, 'Streaker');

        foreach (['2026-11-02', '2026-11-03', '2026-11-04'] as $date) {
            AttendanceRecord::create(['tenant_id' => $tenant->id, 'employee_id' => $employee->id, 'date' => $date, 'clock_in' => '09:00:00', 'clock_out' => '18:00:00', 'expected_start' => '09:00:00', 'expected_end' => '18:00:00', 'status' => 'on_time', 'type' => 'standard', 'flags' => []]);
        }
        AttendanceRecord::create(['tenant_id' => $tenant->id, 'employee_id' => $employee->id, 'date' => '2026-11-05', 'clock_in' => '09:30:00', 'clock_out' => '18:00:00', 'expected_start' => '09:00:00', 'expected_end' => '18:00:00', 'status' => 'late', 'type' => 'standard', 'flags' => ['late']]);
        foreach (['2026-11-06', '2026-11-09'] as $date) {
            AttendanceRecord::create(['tenant_id' => $tenant->id, 'employee_id' => $employee->id, 'date' => $date, 'clock_in' => '09:00:00', 'clock_out' => '18:00:00', 'expected_start' => '09:00:00', 'expected_end' => '18:00:00', 'status' => 'on_time', 'type' => 'standard', 'flags' => []]);
        }

        $this->freeze('2026-11-30 23:59:00');

        $this->assertSame(3.0, $this->metric($tenant, '2026-11-01', 'clockwork_royalty', $employee), 'the late day did not break the streak');
    }

    #[Test]
    public function timesheet_done_needs_every_working_day_submitted_on_time(): void
    {
        $tenant = $this->tenant();
        $full = $this->person($tenant, 'Full Sheets');
        $partial = $this->person($tenant, 'Partial Sheets');

        $days = $this->workingDaysIn('2026-11-01');
        $fullSheet = Timesheet::create(['tenant_id' => $tenant->id, 'employee_id' => $full->id, 'week_start' => $days[0], 'status' => 'approved', 'total_hours' => 8 * count($days)]);
        $partialSheet = Timesheet::create(['tenant_id' => $tenant->id, 'employee_id' => $partial->id, 'week_start' => $days[0], 'status' => 'approved', 'total_hours' => 8 * (count($days) - 1)]);

        foreach ($days as $i => $date) {
            TimesheetDay::create(['tenant_id' => $tenant->id, 'timesheet_id' => $fullSheet->id, 'entry_date' => $date, 'status' => TimesheetDay::STATUS_APPROVED, 'late' => false]);
            if ($i > 0) {
                TimesheetDay::create(['tenant_id' => $tenant->id, 'timesheet_id' => $partialSheet->id, 'entry_date' => $date, 'status' => TimesheetDay::STATUS_APPROVED, 'late' => false]);
            }
        }

        $this->freeze('2026-11-30 23:59:00');

        $this->assertSame((float) count($days), $this->metric($tenant, '2026-11-01', 'timesheet_done', $full));
        $this->assertNull($this->metric($tenant, '2026-11-01', 'timesheet_done', $partial), 'a missing day still counted as fully compliant');
    }

    #[Test]
    public function knowledge_bank_tot_comments_and_reactions_drive_their_own_awards(): void
    {
        $tenant = $this->tenant();
        $author = $this->person($tenant, 'Author');
        $asker = $this->person($tenant, 'Asker');
        $presenter = $this->person($tenant, 'Presenter');
        $hypeGiver = $this->person($tenant, 'Hype Giver');
        $wishOwner = $this->person($tenant, 'Wish Owner');
        $reactorOne = $this->person($tenant, 'Reactor One');
        $reactorTwo = $this->person($tenant, 'Reactor Two');

        Carbon::setTestNow('2026-11-10 10:00:00');

        $segment = KnowledgeSegment::create(['tenant_id' => $tenant->id, 'label' => 'General']);
        KnowledgeEntry::create(['tenant_id' => $tenant->id, 'seg_id' => $segment->id, 'employee_id' => $author->id, 'title' => 'Entry 1', 'body' => 'x']);
        KnowledgeEntry::create(['tenant_id' => $tenant->id, 'seg_id' => $segment->id, 'employee_id' => $author->id, 'title' => 'Entry 2', 'body' => 'x']);

        // tot_sessions is unique per (tenant, year, month) — one roster slot a month — so
        // "distinct sessions participated in" is naturally capped at 1 within a single
        // month; the award still holds meaning across months, just not demonstrable here.
        $session = TotSession::create(['tenant_id' => $tenant->id, 'year' => 2026, 'month' => 11, 'presenter_employee_id' => $presenter->id, 'status' => 'done', 'title' => 'S1']);
        TotComment::create(['tenant_id' => $tenant->id, 'session_id' => $session->id, 'employee_id' => $asker->id, 'body' => 'Question 1?']);
        TotReaction::create(['tenant_id' => $tenant->id, 'session_id' => $session->id, 'employee_id' => $reactorOne->id, 'emoji' => '🔥']);
        TotReaction::create(['tenant_id' => $tenant->id, 'session_id' => $session->id, 'employee_id' => $reactorTwo->id, 'emoji' => '👏']);

        $wish = BirthdayWish::create(['tenant_id' => $tenant->id, 'employee_id' => $wishOwner->id, 'author_id' => $wishOwner->id, 'celebrated_on' => '2026-11-10', 'body' => 'HBD']);
        BirthdayWishReaction::create(['tenant_id' => $tenant->id, 'wish_id' => $wish->id, 'employee_id' => $hypeGiver->id, 'emoji' => '🎉']);

        $this->freeze('2026-11-30 23:59:00');

        $this->assertSame(2.0, $this->metric($tenant, '2026-11-01', 'walking_wikipedia', $author));
        $this->assertSame(1.0, $this->metric($tenant, '2026-11-01', 'question_department', $asker), 'one session this month');
        $this->assertSame(2.0, $this->metric($tenant, '2026-11-01', 'mic_drop_mentor', $presenter), 'two reactions on the presented session');
        $this->assertSame(1.0, $this->metric($tenant, '2026-11-01', 'chief_hype_officer', $hypeGiver), 'one colleague hyped up');
    }

    #[Test]
    public function a_tie_in_the_snapshot_gives_every_leader_the_win(): void
    {
        $tenant = $this->tenant();
        $ali = $this->person($tenant, 'Ali');
        $bakar = $this->person($tenant, 'Bakar');

        foreach ([$ali, $bakar] as $employee) {
            Carbon::setTestNow('2026-11-04 10:00:00');
            $card = $employee->workItems()->create(['tenant_id' => $tenant->id, 'title' => 'Tied card', 'type' => 'task', 'priority' => 'low', 'status' => 'todo', 'progress' => 0]);
            Carbon::setTestNow('2026-11-05 10:00:00');
            $card->update(['status' => 'done', 'done_at' => now()]);
        }

        $this->freeze('2026-11-30 23:59:00');
        $this->publish('2026-12-01 08:00:00');

        $winners = DB::table('award_results')->where('tenant_id', $tenant->id)
            ->whereDate('month', '2026-11-01')->where('award_key', 'done_and_dusted')->pluck('employee_id')->sort()->values()->all();
        $this->assertSame([$ali->id, $bakar->id], $winners, 'a tie did not give both leaders the win');
    }

    #[Test]
    public function a_holiday_on_the_1st_pushes_publish_to_the_2nd(): void
    {
        $tenant = $this->tenant();
        $employee = $this->person($tenant, 'June Winner');

        Carbon::setTestNow('2026-05-01 10:00:00');
        $card = $employee->workItems()->create(['tenant_id' => $tenant->id, 'title' => 'May card', 'type' => 'task', 'priority' => 'low', 'status' => 'todo', 'progress' => 0]);
        Carbon::setTestNow('2026-05-04 10:00:00');
        $card->update(['status' => 'done', 'done_at' => now()]);

        PublicHoliday::create(['tenant_id' => $tenant->id, 'date' => '2026-06-01', 'name' => 'Holiday']);

        $this->freeze('2026-05-31 23:59:00');

        $this->publish('2026-06-01 08:00:00');
        $this->assertSame(0, DB::table('award_results')->where('tenant_id', $tenant->id)->whereDate('month', '2026-05-01')->count(), 'published on a holiday');

        $this->publish('2026-06-02 08:00:00');
        $this->assertGreaterThan(0, DB::table('award_results')->where('tenant_id', $tenant->id)->whereDate('month', '2026-05-01')->count(), 'did not publish the day after the holiday');
    }

    #[Test]
    public function the_two_commands_never_mix_up_two_tenants(): void
    {
        $tenantA = $this->tenant('tenant-a');
        $tenantB = $this->tenant('tenant-b');
        $ownerA = $this->person($tenantA, 'Owner A');
        $ownerB = $this->person($tenantB, 'Owner B');

        foreach ([[$ownerA, $tenantA], [$ownerB, $tenantB]] as [$owner, $tenant]) {
            Carbon::setTestNow('2026-11-04 10:00:00');
            $card = $owner->workItems()->create(['tenant_id' => $tenant->id, 'title' => 'Card', 'type' => 'task', 'priority' => 'low', 'status' => 'todo', 'progress' => 0]);
            Carbon::setTestNow('2026-11-05 10:00:00');
            $card->update(['status' => 'done', 'done_at' => now()]);
        }

        $this->freeze('2026-11-30 23:59:00');
        $this->publish('2026-12-01 08:00:00');

        $winnersA = DB::table('award_results')->where('tenant_id', $tenantA->id)->whereDate('month', '2026-11-01')->where('award_key', 'done_and_dusted')->pluck('employee_id')->all();
        $winnersB = DB::table('award_results')->where('tenant_id', $tenantB->id)->whereDate('month', '2026-11-01')->where('award_key', 'done_and_dusted')->pluck('employee_id')->all();
        $this->assertSame([$ownerA->id], $winnersA);
        $this->assertSame([$ownerB->id], $winnersB);
    }
}
