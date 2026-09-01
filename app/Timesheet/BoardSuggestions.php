<?php

declare(strict_types=1);

namespace App\Timesheet;

use App\Models\Employee;
use App\Models\Timesheet;
use App\Models\TimesheetEntry;
use App\Models\WorkItem;
use App\Models\WorkItemProgressStint;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Which board cards belong on which day of a capture week: the ones that sat in In
 * Progress or In Review, which is what the card's stints record.
 *
 * Sits beside LockedDays: both turn a fact the staffer did not type into rows for the
 * capture grid. The difference is ownership. LockedDays rows are HR's (approved leave,
 * public holidays) — locked, regenerated on every save. These are the staffer's own: with
 * no Add button on the capture screen they are the only way work reaches a timesheet, but
 * nothing is written until a row is given a percentage and saved, and a row struck off a
 * day is not offered again. Read-only; this class never writes.
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
        $dismissed = $this->dismissedCardDays($employee, $start);
        $categories = $this->categoryFor($cards);

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

                // Already logged that day, struck off by the staffer, or already
                // suggested by an earlier stint of the same card (a card can bounce in
                // and out twice in one day).
                if (isset($logged[$iso][$cardId]) || isset($dismissed[$iso][$cardId]) || isset($out[$iso][$cardId])) {
                    continue;
                }

                $card = $cards[$cardId];

                $out[$iso][$cardId] = [
                    'work_item_id' => $cardId,
                    'title' => $card->title,
                    'category_id' => $categories[$cardId] ?? null,
                    'project_id' => $card->project_id ? (int) $card->project_id : null,
                    // The staffer tags what they were doing (Technical, Meeting, ...) in
                    // the row's own overlay; the card does not carry it.
                    'sub_pillar_id' => null,
                    // The note starts empty: the card's title names the line by itself
                    // now, and the card's description is spec text, not a staffer note.
                    'description' => '',
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
            ->get(['id', 'title', 'project_id', 'timesheet_category_id'])
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
     * The effort type each card's rows are costed as: the card's own choice, and nothing
     * else. The board asks for the category on the card, so there is no project to infer
     * one from — and no automatic overhead bucket either.
     *
     * A card that has not answered comes back null: Others is where work the company does
     * for itself belongs, and filing an unanswered card there would quietly put whatever it
     * was into the one column the director reads as overhead. The capture screen asks the
     * staffer instead — the row's own edit overlay offers a category picker, and the row is
     * not saved until one is chosen. That choice lands on the entry, not on the card.
     *
     * Public because the capture screen's "restore a struck-off card" list has to offer
     * the row back with the same category the prefill would have given it.
     *
     * @param  Collection<int, WorkItem>  $cards
     * @return array<int, int|null>
     */
    public function categoryFor(Collection $cards): array
    {
        return $cards->mapWithKeys(fn (WorkItem $card) => [
            (int) $card->id => $card->timesheet_category_id ? (int) $card->timesheet_category_id : null,
        ])->all();
    }

    /**
     * Cards the staffer struck off a given day of this week. A removed row must stay
     * removed: the prefill rebuilds itself from the card's stints on every load, so
     * without this the card would be back the moment the page is opened again.
     *
     * @return array<string, array<int, true>>
     */
    private function dismissedCardDays(Employee $employee, CarbonImmutable $start): array
    {
        $stored = Timesheet::query()
            ->where('employee_id', $employee->id)
            ->whereDate('week_start', $start->toDateString())
            ->value('dismissed_suggestions');

        $stored = is_string($stored) ? json_decode($stored, true) : $stored;

        $out = [];
        foreach ((array) $stored as $iso => $cardIds) {
            foreach ((array) $cardIds as $cardId) {
                $out[(string) $iso][(int) $cardId] = true;
            }
        }

        return $out;
    }
}
