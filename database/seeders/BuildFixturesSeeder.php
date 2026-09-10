<?php

namespace Database\Seeders;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Project;
use App\Models\PublicHoliday;
use App\Models\Scopes\ParentOnly;
use App\Models\Tenant;
use App\Models\Timesheet;
use App\Models\TimesheetCategory;
use App\Models\WorkItem;
use App\Models\WorkItemProgressStint;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BuildFixturesSeeder extends Seeder
{
    private const TENANT_ID = 1;

    /** @var list<string> */
    private const EMAILS = [
        'hidayahsuffya.unijaya@gmail.com',
        'kussairi.unijaya@gmail.com',
        'haryati.unijaya@gmail.com',
        'shahril.unijaya@gmail.com',
        'shazwanshah.unijaya@gmail.com',
    ];

    /** Recognisable prefix, so a rerun (or a manual cleanup) can find and drop every row this seeder made. */
    private const TITLE_PREFIX = 'Fixture: ';

    /** @var list<string> */
    private const CARD_TOPICS = [
        'Draft onboarding checklist', 'Review payroll batch', 'Client site audit',
        'Update HR policy doc', 'Prepare monthly report', 'Fix attendance bug',
        'Plan next quarter roadmap', 'Vendor invoice reconciliation', 'Staff training session',
        'System backup verification', 'Data migration cleanup', 'Compliance review',
        'Board meeting prep', 'Customer support ticket', 'Server maintenance window',
    ];

    private CarbonImmutable $windowStart;

    private CarbonImmutable $windowEnd;

    /**
     * Rebuilds three full calendar months of demo attendance, board cards and
     * timesheets for a fixed set of named employees. Delete-then-insert per table,
     * like TimesheetSeeder, so running it again never doubles the figures up.
     *
     * mt_srand(2026) makes every "random" choice below reproducible: a rerun with
     * an unchanged window produces byte-identical rows, which is what makes the
     * delete-then-insert idempotent rather than merely non-duplicating.
     */
    public function run(): void
    {
        mt_srand(2026);

        $tenant = Tenant::find(self::TENANT_ID);
        if (! $tenant) {
            return;
        }

        $now = CarbonImmutable::now();
        $this->windowStart = $now->startOfMonth()->subMonths(3);
        $this->windowEnd = $now->startOfMonth()->subMonth()->endOfMonth()->startOfDay();

        $employees = $this->resolveEmployees();
        if ($employees->isEmpty()) {
            return;
        }

        $workingDays = $this->workingDays();
        if (empty($workingDays)) {
            return;
        }

        $this->seedAttendance($employees, $workingDays);
        $cardIdsByEmployee = $this->seedCards($employees, $workingDays);
        $this->seedTimesheets($employees, $workingDays, $cardIdsByEmployee);
    }

    /**
     * The five named employees, in EMAILS order (not query order) so the mt_rand
     * sequence a rerun produces never depends on how the DB happens to order rows.
     * Global scope is inactive in seeders, so scope to the tenant explicitly.
     *
     * @return Collection<int, Employee>
     */
    private function resolveEmployees(): Collection
    {
        $byEmail = Employee::where('tenant_id', self::TENANT_ID)
            ->whereHas('user', fn ($q) => $q->whereIn('email', self::EMAILS))
            ->with('user')
            ->get()
            ->keyBy(fn (Employee $e) => $e->user->email);

        return collect(self::EMAILS)->map(fn (string $email) => $byEmail->get($email))->filter()->values();
    }

    /**
     * Monday-to-Friday dates in the window, minus this tenant's public holidays.
     *
     * @return list<CarbonImmutable>
     */
    private function workingDays(): array
    {
        $holidays = PublicHoliday::where('tenant_id', self::TENANT_ID)
            ->whereBetween('date', [$this->windowStart->toDateString(), $this->windowEnd->toDateString()])
            ->pluck('date')
            ->map(fn ($d) => CarbonImmutable::parse($d)->toDateString())
            ->all();

        $days = [];
        for ($day = $this->windowStart; $day->lte($this->windowEnd); $day = $day->addDay()) {
            if ($day->isWeekday() && ! in_array($day->toDateString(), $holidays, true)) {
                $days[] = $day;
            }
        }

        return $days;
    }

    /**
     * One attendance row per working day per employee: roughly 1 in 8 late,
     * roughly 1 in 25 skipped outright (no punch that day at all).
     *
     * @param  Collection<int, Employee>  $employees
     * @param  list<CarbonImmutable>  $workingDays
     */
    private function seedAttendance(Collection $employees, array $workingDays): void
    {
        AttendanceRecord::where('tenant_id', self::TENANT_ID)
            ->whereIn('employee_id', $employees->pluck('id'))
            ->whereBetween('date', [$this->windowStart->toDateString(), $this->windowEnd->toDateString()])
            ->delete();

        $rows = [];
        $now = now();

        foreach ($employees as $employee) {
            foreach ($workingDays as $day) {
                if (mt_rand(1, 25) === 1) {
                    continue;
                }

                $late = mt_rand(1, 8) === 1;
                // 08:30–09:00 on time, 09:01–09:20 late (minutes past midnight).
                $clockInMinutes = $late ? mt_rand(541, 560) : mt_rand(510, 540);
                $clockOutMinutes = mt_rand(1050, 1110); // 17:30–18:30

                $rows[] = [
                    'tenant_id' => self::TENANT_ID,
                    'employee_id' => $employee->id,
                    'date' => $day->toDateString(),
                    'clock_in' => $this->minutesToTime($clockInMinutes),
                    'clock_out' => $this->minutesToTime($clockOutMinutes),
                    'status' => $late ? 'late' : 'on_time',
                    'type' => 'standard',
                    'worked_minutes' => $clockOutMinutes - $clockInMinutes,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('attendance_records')->insert($chunk);
        }
    }

    private function minutesToTime(int $minutesPastMidnight): string
    {
        return sprintf('%02d:%02d:00', intdiv($minutesPastMidnight, 60), $minutesPastMidnight % 60);
    }

    /**
     * 8–12 fixture cards per employee, two of them carrying subtasks assigned to a
     * different fixture employee, plus a progress stint for every card that is or
     * was 'prog'.
     *
     * @param  Collection<int, Employee>  $employees
     * @param  list<CarbonImmutable>  $workingDays
     * @return array<int, list<int>> employee_id => fixture card ids
     */
    private function seedCards(Collection $employees, array $workingDays): array
    {
        // ParentOnly hides subtask rows from the default query, so include it explicitly
        // or a rerun would leave every previous subtask behind.
        WorkItem::withoutGlobalScope(ParentOnly::class)
            ->where('tenant_id', self::TENANT_ID)
            ->where('title', 'like', self::TITLE_PREFIX.'%')
            ->delete();

        $types = ['task', 'assignment', 'adhoc'];
        $statuses = ['todo', 'prog', 'review', 'done'];
        $priorities = ['high', 'medium', 'low'];
        $labelKeys = array_keys(WorkItem::LABELS);

        $cardIdsByEmployee = [];
        $cardsForSubtasks = []; // [['id' => int, 'employee_id' => int], ...]
        $sortCounters = []; // "employee_id:status" => next sort_order

        foreach ($employees as $employee) {
            $cardIdsByEmployee[$employee->id] = [];
            $count = mt_rand(8, 12);

            for ($i = 0; $i < $count; $i++) {
                $type = $types[array_rand($types)];
                $status = $statuses[array_rand($statuses)];
                $priority = $priorities[array_rand($priorities)];
                $dueAt = $workingDays[mt_rand(0, count($workingDays) - 1)];
                $topic = self::CARD_TOPICS[mt_rand(0, count(self::CARD_TOPICS) - 1)];

                $sortKey = $employee->id.':'.$status;
                $sortOrder = $sortCounters[$sortKey] ??= 0;
                $sortCounters[$sortKey]++;

                $card = WorkItem::create([
                    'tenant_id' => self::TENANT_ID,
                    'employee_id' => $employee->id,
                    'title' => self::TITLE_PREFIX.$topic.' #'.($i + 1),
                    'type' => $type,
                    'status' => $status,
                    'priority' => $priority,
                    'due_at' => $dueAt->toDateString(),
                    'done_at' => $status === 'done' ? $dueAt->setTime(mt_rand(9, 17), mt_rand(0, 59)) : null,
                    'labels' => $this->randomLabels($labelKeys),
                    'sort_order' => $sortOrder,
                ]);

                $cardIdsByEmployee[$employee->id][] = $card->id;
                $cardsForSubtasks[] = ['id' => $card->id, 'employee_id' => $employee->id, 'due_at' => $dueAt];

                $this->seedProgressStint($card, $status, $dueAt);
            }
        }

        $this->seedSubtasks($employees, $cardsForSubtasks, $workingDays);

        return $cardIdsByEmployee;
    }

    /** @param list<string> $labelKeys */
    private function randomLabels(array $labelKeys): array
    {
        $n = mt_rand(0, 2);
        shuffle($labelKeys);

        return array_slice($labelKeys, 0, $n);
    }

    /** A stint for every card that is or was 'prog' — 'prog' itself is still open (ended_at null). */
    private function seedProgressStint(WorkItem $card, string $status, CarbonImmutable $dueAt): void
    {
        if (! in_array($status, ['prog', 'review', 'done'], true)) {
            return;
        }

        $startedAt = $dueAt->subDays(mt_rand(1, 10))->setTime(mt_rand(9, 16), 0);
        if ($startedAt->lt($this->windowStart)) {
            $startedAt = $this->windowStart->setTime(9, 0);
        }

        WorkItemProgressStint::create([
            'tenant_id' => self::TENANT_ID,
            'work_item_id' => $card->id,
            'started_at' => $startedAt,
            'ended_at' => $status === 'prog' ? null : $startedAt->addHours(mt_rand(4, 72)),
        ]);
    }

    /**
     * 2 of the freshly made cards get 2–3 subtasks each, owned by a different
     * fixture employee than the parent card.
     *
     * @param  Collection<int, Employee>  $employees
     * @param  list<array{id:int, employee_id:int, due_at:CarbonImmutable}>  $cards
     * @param  list<CarbonImmutable>  $workingDays
     */
    private function seedSubtasks(Collection $employees, array $cards, array $workingDays): void
    {
        if (count($cards) < 2 || $employees->count() < 2) {
            return;
        }

        $chosen = array_rand($cards, 2);
        foreach ((array) $chosen as $index) {
            $parent = $cards[$index];
            $others = $employees->reject(fn (Employee $e) => $e->id === $parent['employee_id'])->values();
            $subtaskEmployee = $others[mt_rand(0, $others->count() - 1)];

            $subtaskCount = mt_rand(2, 3);
            for ($s = 0; $s < $subtaskCount; $s++) {
                $status = mt_rand(0, 1) === 1 ? 'done' : 'todo';
                $dueAt = $workingDays[mt_rand(0, count($workingDays) - 1)];

                WorkItem::create([
                    'tenant_id' => self::TENANT_ID,
                    'parent_id' => $parent['id'],
                    'employee_id' => $subtaskEmployee->id,
                    'title' => self::TITLE_PREFIX.'Subtask #'.($s + 1),
                    'status' => $status,
                    'due_at' => $dueAt->toDateString(),
                    'done_at' => $status === 'done' ? $dueAt->setTime(mt_rand(9, 17), 0) : null,
                    'sort_order' => $s,
                ]);
            }
        }
    }

    /**
     * One timesheet per week in the window per employee, with two entries per
     * working day summing to 100%. Weeks older than 2 weeks (from now) are
     * 'approved'; the rest are 'submitted'.
     *
     * @param  Collection<int, Employee>  $employees
     * @param  list<CarbonImmutable>  $workingDays
     * @param  array<int, list<int>>  $cardIdsByEmployee
     */
    private function seedTimesheets(Collection $employees, array $workingDays, array $cardIdsByEmployee): void
    {
        $categories = TimesheetCategory::where('tenant_id', self::TENANT_ID)
            ->where('is_active', true)
            ->whereNotIn('name', TimesheetCategory::GENERATED)
            ->get();
        if ($categories->isEmpty()) {
            return;
        }

        $projects = Project::where('tenant_id', self::TENANT_ID)->where('is_active', true)->with('categories')->get();
        $decidedById = $employees->first(fn (Employee $e) => $e->user->email === 'haryati.unijaya@gmail.com')?->id;
        $approvedCutoff = CarbonImmutable::now()->subWeeks(2)->startOfWeek();

        /** @var array<string, list<CarbonImmutable>> $daysByWeek */
        $daysByWeek = [];
        foreach ($workingDays as $day) {
            $daysByWeek[$day->startOfWeek()->toDateString()][] = $day;
        }
        ksort($daysByWeek);

        // Range delete, not whereIn equality: Eloquent's `date` cast serialises week_start
        // with a 00:00:00 time part on save (see Timesheet::scopeForWeek's own docblock),
        // so a plain string-equality filter here would silently delete nothing on a rerun.
        $firstWeek = CarbonImmutable::parse(array_key_first($daysByWeek));
        $lastWeek = CarbonImmutable::parse(array_key_last($daysByWeek));
        Timesheet::where('tenant_id', self::TENANT_ID)
            ->whereIn('employee_id', $employees->pluck('id'))
            ->where('week_start', '>=', $firstWeek->toDateString())
            ->where('week_start', '<', $lastWeek->addDay()->toDateString())
            ->delete();

        foreach ($employees as $employee) {
            $cardIds = $cardIdsByEmployee[$employee->id] ?? [];
            if (empty($cardIds)) {
                continue;
            }

            foreach ($daysByWeek as $weekStart => $days) {
                $weekStart = CarbonImmutable::parse($weekStart);
                $approved = $weekStart->lt($approvedCutoff);
                $submittedAt = $weekStart->addDays(4)->setTime(17, 0);

                $timesheet = Timesheet::create([
                    'tenant_id' => self::TENANT_ID,
                    'employee_id' => $employee->id,
                    'week_start' => $weekStart->toDateString(),
                    'week_label' => 'Week '.$weekStart->isoWeek().' · '.$weekStart->format('j').'–'.$weekStart->addDays(6)->format('j M'),
                    'status' => $approved ? 'approved' : 'submitted',
                    'submitted_at' => $submittedAt,
                    'decided_at' => $approved ? $submittedAt->addDays(mt_rand(1, 3)) : null,
                    'decided_by_id' => $approved ? $decidedById : null,
                ]);

                $entries = [];
                $now = now();
                foreach ($days as $day) {
                    [$firstPct, $secondPct] = $this->splitHundred();
                    $picks = $this->pickTwoCategories($categories);

                    foreach ([[$picks[0], $firstPct], [$picks[1], $secondPct]] as [$category, $pct]) {
                        $projectId = $category->requires_project
                            ? $this->pickProject($projects, $category->id)?->id
                            : null;

                        $entries[] = [
                            'tenant_id' => self::TENANT_ID,
                            'timesheet_id' => $timesheet->id,
                            'entry_date' => $day->toDateString(),
                            'category_id' => $category->id,
                            'project_id' => $projectId,
                            'work_item_id' => $cardIds[mt_rand(0, count($cardIds) - 1)],
                            'percentage' => $pct,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }

                foreach (array_chunk($entries, 200) as $chunk) {
                    DB::table('timesheet_entries')->insert($chunk);
                }
            }
        }
    }

    /** @return array{0:int, 1:int} two integers summing to 100 */
    private function splitHundred(): array
    {
        $first = mt_rand(20, 80);

        return [$first, 100 - $first];
    }

    /**
     * Two distinct categories, picked deterministically off mt_rand.
     *
     * @param  Collection<int, TimesheetCategory>  $categories
     * @return array{0:TimesheetCategory, 1:TimesheetCategory}
     */
    private function pickTwoCategories(Collection $categories): array
    {
        $n = $categories->count();
        $i = mt_rand(0, $n - 1);
        $j = mt_rand(0, $n - 1);
        if ($j === $i) {
            $j = ($j + 1) % $n;
        }

        return [$categories[$i], $categories[$j]];
    }

    /**
     * A project tagged with this category, or an untagged one (same "says nothing,
     * so it's offered everywhere" rule as WorkItem::projectOptions()). Null when the
     * tenant has no matching project at all.
     *
     * @param  Collection<int, Project>  $projects
     */
    private function pickProject(Collection $projects, int $categoryId): ?Project
    {
        $eligible = $projects->filter(fn (Project $p) => $p->categories->isEmpty() || $p->categories->contains('id', $categoryId)
        )->values();

        return $eligible->isEmpty() ? null : $eligible[mt_rand(0, $eligible->count() - 1)];
    }
}
