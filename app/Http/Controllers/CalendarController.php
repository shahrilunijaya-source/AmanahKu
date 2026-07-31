<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CompanyEvent;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\PublicHoliday;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Read-only company-wide time-off calendar.
 *
 * Aggregates EXISTING data — approved leave, public holidays and company
 * events — into a Mon–Sun month grid. No model, no migration, no writes.
 * Tenant isolation comes from the BelongsToTenant global scope on each model,
 * so no manual tenant_id filters are added here (that would double-scope).
 * Everyone may view; there is no privilege gate.
 */
class CalendarController extends Controller
{
    /**
     * Build the month grid plus navigation labels and the "who's out" summary.
     *
     * @return array{
     *     month:string, monthValue:string, prevMonth:string, nextMonth:string,
     *     weekdays:array<int,string>, weeks:array<int,array<int,array<string,mixed>>>,
     *     outThisMonth:Collection, holidaysThisMonth:Collection, eventsThisMonth:Collection
     * }
     */
    public function screenData(Request $request, ?Employee $employee): array
    {
        $month = $this->resolveMonth($request->query('month'));

        $monthStart = $month->startOfMonth();
        $monthEnd = $month->endOfMonth();

        // Grid spans whole weeks (Mon–Sun) covering the visible month.
        $gridStart = $monthStart->startOfWeek(CarbonInterface::MONDAY);
        $gridEnd = $monthEnd->endOfWeek(CarbonInterface::SUNDAY);

        $leave = $this->approvedLeaveInRange($gridStart, $gridEnd);
        $holidays = $this->holidaysInRange($gridStart, $gridEnd);
        $events = $this->eventsInRange($gridStart, $gridEnd);
        // Birthdays recur yearly, so they are matched by month+day (not a date range) —
        // load every active person with a DOB once and filter per cell.
        $birthdays = $this->birthdayPeople();

        $today = CarbonImmutable::now();
        $weeks = $this->buildWeeks($gridStart, $gridEnd, $month, $today, $leave, $holidays, $events, $birthdays);

        return [
            'month' => $month->format('F Y'),
            'monthValue' => $month->format('Y-m'),
            'prevMonth' => $month->subMonth()->format('Y-m'),
            'nextMonth' => $month->addMonth()->format('Y-m'),
            'weekdays' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
            'weeks' => $weeks,
            'outThisMonth' => $this->outThisMonth($leave, $monthStart, $monthEnd),
            'holidaysThisMonth' => $holidays->filter(
                fn (PublicHoliday $h) => $h->date->betweenIncluded($monthStart, $monthEnd)
            )->values(),
            'eventsThisMonth' => $events->filter(
                fn (CompanyEvent $e) => $e->event_date->betweenIncluded($monthStart, $monthEnd)
            )->values(),
            'birthdaysThisMonth' => $birthdays
                ->filter(fn (Employee $e) => (int) $e->date_of_birth->format('n') === $monthStart->month)
                ->sortBy(fn (Employee $e) => (int) $e->date_of_birth->format('j'))
                ->values(),
        ];
    }

    /** Parse ?month=YYYY-MM, falling back to the current (app "now") month. */
    private function resolveMonth(?string $raw): CarbonImmutable
    {
        // createFromFormat silently rolls an out-of-range month (e.g. 2026-13 →
        // 2027-01) instead of throwing, so validate the month integer explicitly.
        if (is_string($raw) && preg_match('/^(\d{4})-(\d{2})$/', $raw, $m) === 1) {
            $year = (int) $m[1];
            $monthNo = (int) $m[2];
            if ($monthNo >= 1 && $monthNo <= 12) {
                return CarbonImmutable::create($year, $monthNo, 1)->startOfMonth();
            }
        }

        return CarbonImmutable::now()->startOfMonth();
    }

    /** Approved leave overlapping the visible grid (date_from..date_to spans). */
    private function approvedLeaveInRange(CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        return LeaveRequest::with(['employee', 'leaveType'])
            ->where('status', 'approved')
            ->whereHas('employee', fn ($q) => $q->active())
            ->whereDate('date_from', '<=', $end->toDateString())
            ->whereDate('date_to', '>=', $start->toDateString())
            ->get();
    }

    private function holidaysInRange(CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        return PublicHoliday::whereDate('date', '>=', $start->toDateString())
            ->whereDate('date', '<=', $end->toDateString())
            ->orderBy('date')
            ->get();
    }

    private function eventsInRange(CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        return CompanyEvent::whereDate('event_date', '>=', $start->toDateString())
            ->whereDate('event_date', '<=', $end->toDateString())
            ->orderBy('event_date')
            ->get();
    }

    /**
     * Slice the grid into weeks of seven day-cells.
     *
     * @return array<int,array<int,array<string,mixed>>>
     */
    private function buildWeeks(
        CarbonImmutable $gridStart,
        CarbonImmutable $gridEnd,
        CarbonImmutable $month,
        CarbonImmutable $today,
        Collection $leave,
        Collection $holidays,
        Collection $events,
        Collection $birthdays,
    ): array {
        $weeks = [];
        $week = [];
        $cursor = $gridStart;

        while ($cursor->lessThanOrEqualTo($gridEnd)) {
            $week[] = $this->buildDay($cursor, $month, $today, $leave, $holidays, $events, $birthdays);

            if (count($week) === 7) {
                $weeks[] = $week;
                $week = [];
            }

            $cursor = $cursor->addDay();
        }

        return $weeks;
    }

    /**
     * Build one day cell with the items falling on that date.
     *
     * @return array{date:CarbonImmutable, inMonth:bool, isToday:bool, leave:Collection, holiday:Collection, events:Collection, birthday:Collection}
     */
    private function buildDay(
        CarbonImmutable $date,
        CarbonImmutable $month,
        CarbonImmutable $today,
        Collection $leave,
        Collection $holidays,
        Collection $events,
        Collection $birthdays,
    ): array {
        return [
            'date' => $date,
            'inMonth' => $date->month === $month->month && $date->year === $month->year,
            'isToday' => $date->isSameDay($today),
            'leave' => $leave->filter(
                fn (LeaveRequest $l) => $date->betweenIncluded($l->date_from, $l->date_to)
            )->values(),
            'holiday' => $holidays->filter(
                fn (PublicHoliday $h) => $h->date->isSameDay($date)
            )->values(),
            'events' => $events->filter(
                fn (CompanyEvent $e) => $e->event_date->isSameDay($date)
            )->values(),
            // Match on month+day so a birthday lands on the same calendar day every year.
            'birthday' => $birthdays->filter(
                fn (Employee $e) => (int) $e->date_of_birth->format('n') === $date->month
                    && (int) $e->date_of_birth->format('j') === $date->day
            )->values(),
        ];
    }

    /** Active people with a known DOB — matched by month+day per cell (recurs yearly). */
    private function birthdayPeople(): Collection
    {
        return Employee::active()
            ->whereNotNull('date_of_birth')
            ->get(['id', 'name', 'initials', 'avatar_color', 'date_of_birth']);
    }

    /**
     * Distinct people on approved leave at any point this month, with the
     * span clamped to the visible month for a readable summary line.
     */
    private function outThisMonth(Collection $leave, CarbonImmutable $monthStart, CarbonImmutable $monthEnd): Collection
    {
        return $leave
            ->filter(fn (LeaveRequest $l) => $l->date_from->lessThanOrEqualTo($monthEnd)
                && $l->date_to->greaterThanOrEqualTo($monthStart))
            ->sortBy(fn (LeaveRequest $l) => $l->date_from->timestamp)
            ->values();
    }
}
