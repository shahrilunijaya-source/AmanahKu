<?php

declare(strict_types=1);

namespace App\Timesheet;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\Timesheet;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Single source of truth for weekly timesheet compliance.
 *
 * A week is "complete" when every weekday Mon–Fri of that week has timesheet
 * entries summing to 100% (±0.01) — the same per-day rule the capture screen
 * enforces on submit. Weekend days are ignored. Read-only: never writes.
 */
final class TimesheetCompliance
{
    /** Tolerance for floating per-day percentage totals. */
    private const EPSILON = 0.01;

    /** Monday 00:00 of $ref's ISO week. */
    public function weekStart(CarbonInterface $ref): CarbonImmutable
    {
        return CarbonImmutable::parse($ref)->startOfWeek();
    }

    /** That week's Friday 17:00 — the submission deadline. ($weekStart is Monday.) */
    public function deadline(CarbonInterface $weekStart): CarbonImmutable
    {
        return CarbonImmutable::parse($weekStart)->startOfDay()->addDays(4)->setTime(17, 0);
    }

    /** True when every weekday Mon–Fri of $weekStart sums to 100% (±0.01). */
    public function isComplete(Employee $employee, CarbonInterface $weekStart): bool
    {
        $start = CarbonImmutable::parse($weekStart)->startOfDay();

        $sheet = Timesheet::with('entries')
            ->where('employee_id', $employee->id)
            ->forWeek($start)
            ->first();

        return $sheet !== null && $this->weekdaysComplete($sheet->entries, $start);
    }

    /**
     * Not complete AND now() is at/after the Friday deadline. Drives the banner.
     *
     * When the caller has already fetched this week's sheet (e.g. the quick-actions
     * dock loads it for the % tile), pass it with $sheetLoaded=true to skip the
     * duplicate query — $sheet=null then means "loaded, none exists".
     */
    public function isLate(Employee $employee, CarbonInterface $weekStart, ?Timesheet $sheet = null, bool $sheetLoaded = false): bool
    {
        $start = CarbonImmutable::parse($weekStart)->startOfDay();

        // Same eligibility gate the roster/bell apply: an inactive staffer, or one
        // who joined after the week started, is never "overdue" for that week.
        if (! $this->isEligible($employee, $start)) {
            return false;
        }

        if (CarbonImmutable::now()->lessThan($this->deadline($start))) {
            return false;
        }

        $complete = $sheetLoaded
            ? $sheet !== null && $this->weekdaysComplete($sheet->entries, $start)
            : $this->isComplete($employee, $start);

        return ! $complete;
    }

    /**
     * Whether $employee is expected to have filled the $weekStart week: active and
     * already employed when the week began. Mirrors the SQL filter in roster().
     */
    private function isEligible(Employee $employee, CarbonImmutable $weekStart): bool
    {
        if ($employee->status !== 'active') {
            return false;
        }

        return $employee->joined_at === null
            || $employee->joined_at->lessThanOrEqualTo($weekStart);
    }

    /**
     * Every active, eligible employee of $tenant with their status for $weekStart.
     * Sorted late → pending → done, then by name.
     *
     * @return Collection<int, array{employee: Employee, status: 'done'|'pending'|'late'}>
     */
    public function roster(Tenant $tenant, CarbonInterface $weekStart): Collection
    {
        $start = CarbonImmutable::parse($weekStart)->startOfDay();
        $pastDeadline = CarbonImmutable::now()->greaterThanOrEqualTo($this->deadline($start));

        $employees = Employee::active()->where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('joined_at')->orWhereDate('joined_at', '<=', $start->toDateString()))
            ->orderBy('name')
            ->get();

        $sheets = Timesheet::with('entries')
            ->whereIn('employee_id', $employees->pluck('id'))
            ->forWeek($start)
            ->get()
            ->keyBy('employee_id');

        $rank = ['late' => 0, 'pending' => 1, 'done' => 2];

        return $employees
            ->map(function (Employee $e) use ($sheets, $start, $pastDeadline) {
                $sheet = $sheets->get($e->id);
                $complete = $sheet !== null && $this->weekdaysComplete($sheet->entries, $start);
                $status = $complete ? 'done' : ($pastDeadline ? 'late' : 'pending');

                return ['employee' => $e, 'status' => $status];
            })
            ->sortBy(fn (array $r) => sprintf('%d-%s', $rank[$r['status']], $r['employee']->name))
            ->values();
    }

    /**
     * Active, eligible employees of $tenant who are NOT complete for $weekStart.
     *
     * @return Collection<int, Employee>
     */
    public function pending(Tenant $tenant, CarbonInterface $weekStart): Collection
    {
        return $this->roster($tenant, $weekStart)
            ->reject(fn (array $r) => $r['status'] === 'done')
            ->map(fn (array $r) => $r['employee'])
            ->values();
    }

    /** All five weekdays Mon–Fri present and each summing to 100% (±0.01). */
    private function weekdaysComplete(Collection $entries, CarbonImmutable $weekStart): bool
    {
        $byDay = [];
        foreach ($entries as $e) {
            $d = $e->entry_date->toDateString();
            $byDay[$d] = ($byDay[$d] ?? 0) + (float) $e->percentage;
        }

        for ($i = 0; $i < 5; $i++) {
            $day = $weekStart->addDays($i)->toDateString();
            if (! isset($byDay[$day]) || abs($byDay[$day] - 100) >= self::EPSILON) {
                return false;
            }
        }

        return true;
    }
}
