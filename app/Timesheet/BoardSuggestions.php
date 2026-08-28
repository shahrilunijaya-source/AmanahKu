<?php

declare(strict_types=1);

namespace App\Timesheet;

use App\Models\Employee;
use App\Models\Project;
use App\Models\TimesheetEntry;
use App\Models\WorkItem;
use App\Models\WorkItemProgressStint;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Which In Progress board cards belong on which day of a capture week.
 *
 * Sits beside LockedDays: both turn a fact the staffer did not type into rows for the
 * capture grid. The difference is ownership. LockedDays rows are HR's (approved leave,
 * public holidays) — locked, regenerated on every save. These are suggestions: offered
 * once, editable, deletable, and never written unless the staffer gives them a
 * percentage and saves. Read-only; this class never writes.
 */
final class BoardSuggestions
{
    public function __construct(private LockedDays $lockedDays) {}

    /**
     * @return array<string, array<int, array{work_item_id:int, title:string, category_id:?int, project_id:?int, sub_pillar_id:?int, description:string}>>
     *                                                                                                                                                     keyed by ISO date, working days only
     */
    public function forWeek(Employee $employee, CarbonInterface|string $weekStart): array
    {
        $start = CarbonImmutable::parse($weekStart)->startOfDay();
        $end = $start->addDays(5);
        $today = CarbonImmutable::now()->startOfDay();

        $cards = $this->cardsFor($employee);

        if ($cards->isEmpty()) {
            return [];
        }

        $earliest = CarbonImmutable::now()->startOfWeek()->subWeeks(WeekWriter::BACKFILL_WEEKS);
        $locked = $this->lockedDays->forWeek($employee, $start);
        $logged = $this->loggedCardDays($employee, $start, $end);
        $defaults = $this->defaultsFor($employee, $cards);

        $out = [];

        foreach ($this->stintsFor($cards->keys()->all(), $start, $end) as $stint) {
            $from = CarbonImmutable::parse($stint->started_at)->startOfDay();
            $until = $stint->ended_at
                ? CarbonImmutable::parse($stint->ended_at)->startOfDay()
                : $today;

            for ($day = $from->max($start); $day->lessThanOrEqualTo($until->min($end)); $day = $day->addDay()) {
                $iso = $day->toDateString();

                // A day the staffer cannot log against, or a fact HR already owns.
                if ($day->lessThan($earliest) || $day->greaterThan($today)) {
                    continue;
                }

                // The capture grid renders Monday to Friday, plus the first Saturday of
                // the month (Unijaya's TOT half day) — see timesheet-capture.js's days
                // count. A stint running across a weekend must not propose a row for a
                // day that has no column to put it in. DayCapacity::for() cannot answer
                // this: it returns 100.0 for a plain Saturday, since it is asking how
                // full a day must be, not whether the day is worked.
                if ($day->isSunday() || ($day->isSaturday() && ! DayCapacity::isFirstSaturday($day))) {
                    continue;
                }

                $lockedDay = $locked[$iso] ?? null;
                if ($lockedDay !== null && $lockedDay['percentage'] >= DayCapacity::for($day)) {
                    continue;
                }

                $cardId = (int) $stint->work_item_id;

                // Already logged that day, or already suggested by an earlier stint of
                // the same card (a card can bounce in and out twice in one day).
                if (isset($logged[$iso][$cardId]) || isset($out[$iso][$cardId])) {
                    continue;
                }

                $card = $cards[$cardId];
                $default = $defaults[$cardId] ?? ['category_id' => null, 'project_id' => null, 'sub_pillar_id' => null];

                $out[$iso][$cardId] = [
                    'work_item_id' => $cardId,
                    'title' => $card->title,
                    'category_id' => $default['category_id'],
                    'project_id' => $default['project_id'],
                    'sub_pillar_id' => $default['sub_pillar_id'],
                    'description' => (string) ($card->description ?: $card->title),
                ];
            }
        }

        ksort($out);

        return array_map(fn (array $rows) => array_values($rows), $out);
    }

    /**
     * The cards this employee may log against: their own, plus any they were added to as
     * a participant. Archived cards are excluded — an archived card is finished business.
     *
     * @return Collection<int, WorkItem>
     */
    private function cardsFor(Employee $employee): Collection
    {
        return WorkItem::query()
            ->whereNull('archived_at')
            ->where(fn ($q) => $q->where('employee_id', $employee->id)
                ->orWhereHas('participants', fn ($p) => $p->where('employees.id', $employee->id)))
            ->get(['id', 'title', 'description', 'project_id'])
            ->keyBy('id');
    }

    /**
     * Every stint of those cards that touches the week.
     *
     * @param  array<int, int>  $cardIds
     * @return Collection<int, WorkItemProgressStint>
     */
    private function stintsFor(array $cardIds, CarbonImmutable $start, CarbonImmutable $end): Collection
    {
        return WorkItemProgressStint::query()
            ->whereIn('work_item_id', $cardIds)
            ->where('started_at', '<', $end->addDay()->toDateString())
            ->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>=', $start->toDateString()))
            ->orderBy('started_at')
            ->get();
    }

    /**
     * Cards already logged on a given day of this week, so the same card is never offered
     * twice for one day.
     *
     * @return array<string, array<int, true>>
     */
    private function loggedCardDays(Employee $employee, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = TimesheetEntry::query()
            ->whereNotNull('work_item_id')
            ->whereHas('timesheet', fn ($q) => $q->where('employee_id', $employee->id))
            ->whereDate('entry_date', '>=', $start->toDateString())
            ->whereDate('entry_date', '<=', $end->toDateString())
            ->get(['entry_date', 'work_item_id']);

        $out = [];
        foreach ($rows as $row) {
            $out[$row->entry_date->toDateString()][(int) $row->work_item_id] = true;
        }

        return $out;
    }

    /**
     * The category / project / sub-pillar a card's row should arrive with: whatever it was
     * logged as last time, so the staffer picks once and the rest of the week is filled in
     * for them. Falling back to the card's own project plus that project's category when it
     * has exactly one — and to nothing at all otherwise, which the picker then asks for.
     *
     * @param  Collection<int, WorkItem>  $cards
     * @return array<int, array{category_id:?int, project_id:?int, sub_pillar_id:?int}>
     */
    private function defaultsFor(Employee $employee, Collection $cards): array
    {
        $out = [];

        $previous = TimesheetEntry::query()
            ->whereIn('work_item_id', $cards->keys()->all())
            ->whereHas('timesheet', fn ($q) => $q->where('employee_id', $employee->id))
            ->orderBy('entry_date')
            ->orderBy('id')
            ->get(['work_item_id', 'category_id', 'project_id', 'sub_pillar_id']);

        // Ordered oldest first (ties broken by id, since two entries can share a date
        // when a day is split across categories), so the last write per card wins — its
        // most recent logging.
        foreach ($previous as $entry) {
            $out[(int) $entry->work_item_id] = [
                'category_id' => $entry->category_id ? (int) $entry->category_id : null,
                'project_id' => $entry->project_id ? (int) $entry->project_id : null,
                'sub_pillar_id' => $entry->sub_pillar_id ? (int) $entry->sub_pillar_id : null,
            ];
        }

        $needProject = $cards->filter(fn (WorkItem $c) => ! isset($out[$c->id]) && $c->project_id !== null);

        if ($needProject->isNotEmpty()) {
            $projects = Project::with('categories:id')
                ->whereIn('id', $needProject->pluck('project_id')->unique()->all())
                ->get();

            foreach ($needProject as $card) {
                $project = $projects->firstWhere('id', $card->project_id);
                $categories = $project?->categories ?? collect();

                $out[(int) $card->id] = [
                    // Only an unambiguous project answers this. Two categories and the
                    // picker asks; guessing one would file work under the wrong heading.
                    'category_id' => $categories->count() === 1 ? (int) $categories->first()->id : null,
                    'project_id' => (int) $card->project_id,
                    'sub_pillar_id' => null,
                ];
            }
        }

        return $out;
    }
}
