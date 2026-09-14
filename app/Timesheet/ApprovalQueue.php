<?php

declare(strict_types=1);

namespace App\Timesheet;

use App\Models\Employee;
use App\Models\Project;
use App\Models\TimesheetDay;
use App\Models\TimesheetEntry;
use Illuminate\Support\Collection;

/**
 * Timesheet days waiting on one manager: every submitted day from the people who
 * report to them, primary or additional line. The single source for the "To approve"
 * tab on Timesheet Reports and the dashboard's "Waiting on you" row, so the two counts
 * never disagree.
 *
 * Deliberately narrower than TimesheetController::managesDays(): HR and the management
 * tier may act on anyone's day, but their queue would be the whole company and never
 * empty, so it lists direct reports only. They still act through the person panel.
 * Read-only: never writes.
 */
final class ApprovalQueue
{
    /**
     * People with waiting days, oldest waiting day first, each with their days oldest first.
     *
     * @return list<array{employee: Employee, days: list<array{iso: string, weekStart: string, dow: string, dayNum: string, month: string, late: bool, resubmitted: bool, returnReason: ?string, percent: float, lines: list<array{card: string, project: ?string, category: ?string, colour: ?string, percent: float}>}>}>
     */
    public function forManager(Employee $manager): array
    {
        $days = $this->waitingDays($manager);
        if ($days->isEmpty()) {
            return [];
        }

        $entries = TimesheetEntry::with(['category', 'projectRef', 'workItem'])
            ->whereIn('timesheet_id', $days->pluck('timesheet_id')->unique())
            ->get()
            ->groupBy(fn (TimesheetEntry $e) => $e->timesheet_id.'|'.$e->entry_date->toDateString());

        return $days
            ->groupBy(fn (TimesheetDay $d) => $d->timesheet->employee_id)
            ->map(fn (Collection $personDays) => [
                'employee' => $personDays->first()->timesheet->employee,
                'days' => $personDays->map(fn (TimesheetDay $d) => $this->dayRow($d, $entries->get($d->timesheet_id.'|'.$d->entry_date->toDateString(), collect())))->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * Just the totals, for the dashboard row and the tab label.
     *
     * @return array{days: int, people: int, oldest: ?string}
     */
    public function summary(Employee $manager): array
    {
        $days = $this->waitingDays($manager);

        return [
            'days' => $days->count(),
            'people' => $days->pluck('timesheet.employee_id')->unique()->count(),
            'oldest' => $days->first()?->entry_date->toDateString(),
        ];
    }

    /** @return Collection<int, TimesheetDay> */
    private function waitingDays(Employee $manager): Collection
    {
        $reportIds = Employee::active()
            ->whereKeyNot($manager->id)
            ->where(fn ($q) => $q->where('reports_to_id', $manager->id)
                ->orWhereHas('additionalManagers', fn ($m) => $m->whereKey($manager->id)))
            ->pluck('id');

        if ($reportIds->isEmpty()) {
            return collect();
        }

        return TimesheetDay::with('timesheet.employee')
            ->where('status', TimesheetDay::STATUS_SUBMITTED)
            ->whereHas('timesheet', fn ($q) => $q->whereIn('employee_id', $reportIds))
            ->orderBy('entry_date')
            ->get();
    }

    /**
     * @param  Collection<int, TimesheetEntry>  $entries
     * @return array{iso: string, weekStart: string, dow: string, dayNum: string, month: string, late: bool, resubmitted: bool, returnReason: ?string, percent: float, lines: list<array{card: string, project: ?string, category: ?string, colour: ?string, percent: float}>}
     */
    private function dayRow(TimesheetDay $day, Collection $entries): array
    {
        $date = $day->entry_date;

        return [
            'iso' => $date->toDateString(),
            'weekStart' => $date->copy()->startOfWeek()->toDateString(),
            'dow' => $date->format('D'),
            'dayNum' => $date->format('d'),
            'month' => $date->format('M'),
            'late' => (bool) $day->late,
            'resubmitted' => (bool) $day->resubmitted,
            'returnReason' => $day->resubmitted ? $day->return_reason : null,
            'percent' => round((float) $entries->sum('percentage'), 2),
            'lines' => $entries->sortByDesc(fn (TimesheetEntry $e) => (float) $e->percentage)
                ->map(fn (TimesheetEntry $e) => $this->lineRow($e))->values()->all(),
        ];
    }

    /** @return array{card: string, project: ?string, category: ?string, colour: ?string, percent: float} */
    private function lineRow(TimesheetEntry $e): array
    {
        $project = $e->getRelationValue('projectRef'); // eager-loaded; the relation has no generic type for phpstan

        return [
            'card' => (string) ($e->workItem->title ?? $e->category->name ?? $e->project ?? ''),
            'project' => $project instanceof Project ? $project->name : null,
            'category' => $e->category?->name,
            'colour' => $e->category?->colour(),
            'percent' => round((float) $e->percentage, 2),
        ];
    }
}
