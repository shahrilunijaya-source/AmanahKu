<?php

declare(strict_types=1);

namespace App\Timesheet;

use App\Models\Employee;
use App\Models\Project;
use App\Models\Timesheet;
use App\Models\TimesheetCategory;
use App\Models\TimesheetEntry;
use App\Support\HtmlSanitizer;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The whole "save a week's grid as a draft" pipeline, extracted out of
 * TimesheetController::store() so App\Mcp\Tools\SaveTimesheetDraftTool goes
 * through the exact same rules a browser save would: locked-day filtering,
 * the backfill window, normalisation, duplicate-line rejection, the locked-row
 * merge, the draft-only guard, and the transactional whole-week replace.
 *
 * The grid is authoritative for the whole week — save() always replaces every
 * stored entry for that week with what it is given. The MCP tool's "partial
 * change" (one day or a few) is not a different save mode: mergePartialIntoExisting()
 * computes the resulting FULL week first (existing rows overlaid by the changed
 * days), and that full set is what actually gets saved — so the controller and
 * the tool can never learn two different lessons about what "save" means.
 */
final class WeekWriter
{
    /**
     * How far back a staffer may still edit. The current week plus this many earlier weeks.
     *
     * Blocking past days outright is not an option: a forgotten Monday could never reach
     * 100%, so the week could never be submitted. An unbounded window is not either, because
     * it lets somebody backfill months the night before an audit.
     */
    public const BACKFILL_WEEKS = 6;

    public function __construct(private LockedDays $lockedDays, private WeekReconciler $reconciler) {}

    /**
     * Save (or refresh) a week as a draft from a full per-day grid, replacing whatever
     * is currently stored for that week. Optionally submit in the same call once every
     * populated day totals 100%.
     *
     * @param  array<int, array{entry_date:string, category_id:int, project_id?:?int, sub_pillar_id?:?int, percentage:float|int|string, description?:?string}>  $rawEntries  validated, not yet normalised
     * @return array{timesheet: Timesheet, entries: array<int, array<string, mixed>>, locked: array<string, mixed>}
     */
    public function save(Employee $employee, CarbonInterface|string $weekStart, array $rawEntries, ?string $weekLabel, bool $submitNow): array
    {
        $weekStartCarbon = Carbon::parse($weekStart)->startOfDay();
        $locked = $this->lockedDays->forWeek($employee, $weekStartCarbon);

        // D4: a fully locked day (public holiday or whole-day leave) is a fact HR owns —
        // anything typed against it is wrong by definition, so it is dropped. A half-day
        // leave locks only 50%, leaving the staffer to fill the other half, so their rows
        // on a partially locked day are kept and merged with the 50% leave row.
        $userEntries = array_filter(
            $rawEntries,
            function (array $e) use ($locked) {
                $date = Carbon::parse($e['entry_date']);
                $day = $locked[$date->toDateString()] ?? null;

                return $day === null || $day['percentage'] < DayCapacity::for($date);
            }
        );

        $this->assertDatesInWindow($userEntries);

        // Normalise first, then check for duplicates: normalisation is what nulls the
        // project on a standalone category, so two lines that differ only by a stray
        // project_id the category never uses are the same line by the time they are compared.
        $normalised = $this->normaliseEntries($userEntries);
        $this->assertNoDuplicateLines($normalised);

        $entries = $this->reconciler->mergeEntries($employee, $weekStart, $normalised);

        // A fully-locked week may submit with no user rows, but a genuinely empty week
        // must not: mirror submit()'s invariant so a submit_now save can't create a
        // submitted timesheet with zero entries (which would land in the cost report).
        abort_if($submitNow && count($entries) === 0, 422, 'Cannot submit an empty timesheet.');
        if ($submitNow) {
            $this->assertWeekEnded($weekStartCarbon);
            $this->assertNoBlankLines($entries);
            $this->assertDayTotals($entries);
        }

        $timesheet = Timesheet::firstOrNew([
            'employee_id' => $employee->id,
            'week_start' => $weekStartCarbon,
        ]);
        abort_if(
            $timesheet->exists && $timesheet->status !== 'draft',
            422,
            'This week has already been submitted and cannot be edited.'
        );

        DB::transaction(function () use ($timesheet, $weekLabel, $entries, $submitNow) {
            $timesheet->fill(['week_label' => $weekLabel, 'status' => 'draft'])->save();
            // The grid represents the entire week — replace, don't append.
            $timesheet->entries()->delete();
            foreach ($entries as $entry) {
                $timesheet->entries()->create($entry);
            }
            $timesheet->recomputeTotal();

            if ($submitNow) {
                $timesheet->update(['status' => 'submitted', 'submitted_at' => now()]);
            }
        });

        return ['timesheet' => $timesheet, 'entries' => $entries, 'locked' => $locked];
    }

    /**
     * Compute the FULL resulting week from the currently stored draft's own rows
     * (source = null, i.e. never the generated locked rows) overlaid by a partial
     * change: every date present in $partialEntries replaces that day's rows
     * entirely, every other day keeps its stored rows untouched.
     *
     * This is deliberately a pure computation with no write — it is what the MCP
     * save_timesheet_draft tool renders as its "day by day" preview, and what it
     * then hands to save() to actually persist once confirmed. There is exactly
     * one place that decides what "merge a partial change into a week" means.
     *
     * A week with no stored draft yet is treated as entirely empty, so the first
     * save from the tool is just the partial change on its own.
     *
     * @param  array<int, array{entry_date:string, category_id:int, project_id?:?int, sub_pillar_id?:?int, percentage:float|int|string, description?:?string}>  $partialEntries
     * @return array<int, array<string, mixed>> raw entries, same shape as $partialEntries, ready for save()
     */
    public function mergePartialIntoExisting(Employee $employee, CarbonInterface|string $weekStart, array $partialEntries): array
    {
        $weekStartCarbon = Carbon::parse($weekStart)->startOfDay();

        $byDate = [];
        foreach ($this->existingUserEntries($employee, $weekStartCarbon) as $row) {
            $byDate[$row['entry_date']][] = $row;
        }

        $changedByDate = [];
        foreach ($partialEntries as $row) {
            $date = Carbon::parse($row['entry_date'])->toDateString();
            $changedByDate[$date][] = $row;
        }

        foreach ($changedByDate as $date => $rows) {
            // A changed day fully replaces whatever was there — the same "grid is
            // authoritative for the day" rule save() applies to the whole week.
            $byDate[$date] = $rows;
        }

        ksort($byDate);

        return $byDate === [] ? [] : array_merge(...array_values($byDate));
    }

    /**
     * The stored draft's own rows for a week (never the generated locked rows,
     * which are always re-derived, not carried forward) — same source WeekReconciler::reconcile()
     * reads, in the same raw shape save() expects to receive.
     *
     * @return array<int, array<string, mixed>>
     */
    private function existingUserEntries(Employee $employee, CarbonInterface $weekStartCarbon): array
    {
        $timesheet = Timesheet::where('employee_id', $employee->id)
            ->where('week_start', $weekStartCarbon)
            ->first();

        if (! $timesheet) {
            return [];
        }

        return TimesheetEntry::where('timesheet_id', $timesheet->id)
            ->whereNull('source')
            ->get()
            ->map(fn (TimesheetEntry $e) => [
                'entry_date' => Carbon::parse($e->entry_date)->toDateString(),
                'category_id' => $e->category_id,
                'project_id' => $e->project_id,
                'sub_pillar_id' => $e->sub_pillar_id,
                'percentage' => (float) $e->percentage,
                'description' => $e->description,
            ])
            ->all();
    }

    /**
     * Apply business rules to raw validated entries and shape them for persistence:
     * enforce requires_project, sanitise the description, set the legacy `project`
     * string, and derive `hours` from the percentage so manday RM costing (hours *
     * rate) keeps working — one full day at 100% equals one manday
     * (config('manday.hours_per_day') hours).
     *
     * @param  array<int, array<string, mixed>>  $raw
     * @return array<int, array<string, mixed>>
     */
    private function normaliseEntries(array $raw): array
    {
        $hoursPerDay = (float) config('manday.hours_per_day', 8);

        $categories = TimesheetCategory::whereIn('id', collect($raw)->pluck('category_id')->filter()->unique())->get()->keyBy('id');
        $projects = Project::whereIn('id', collect($raw)->pluck('project_id')->filter()->unique())->get()->keyBy('id');

        $out = [];
        // Gathered, not thrown at the first bad row: five lines missing a project are one
        // refusal naming five days, rather than five saves each naming one. A row that
        // fails is skipped, and $out is discarded by the throw below anyway.
        $problems = [];
        foreach ($raw as $i => $e) {
            $category = $categories->get($e['category_id']);
            if (! $category) {
                $problems["entries.$i.category_id"] = 'Unknown category.';

                continue;
            }

            $projectId = $e['project_id'] ?? null;
            $subId = $e['sub_pillar_id'] ?? null;

            if ($category->requires_project) {
                if (! $projectId || ! $projects->has($projectId)) {
                    $problems["entries.$i.project_id"] = 'Choose a project for '.$category->name.'.';

                    continue;
                }
            } else {
                // Standalone categories never carry a project or sub-pillar.
                $projectId = null;
                $subId = null;
            }

            $percentage = round((float) $e['percentage'], 2);
            $projectName = $projectId ? ($projects->get($projectId)->name ?? null) : null;

            $out[] = [
                'entry_date' => Carbon::parse($e['entry_date'])->toDateString(),
                'category_id' => $category->id,
                'project_id' => $projectId,
                'sub_pillar_id' => $subId,
                'percentage' => $percentage,
                'description' => HtmlSanitizer::clean($e['description'] ?? null),
                // Legacy readable fallback for any code still reading the string column.
                'project' => trim($category->name.($projectName ? ' — '.$projectName : '')),
                // Hours derived from percentage so manday RM costing keeps working.
                'hours' => round($percentage / 100 * $hoursPerDay, 2),
            ];
        }

        if ($problems !== []) {
            throw ValidationException::withMessages($problems);
        }

        return $out;
    }

    /**
     * A line added but never costed may sit in a draft (percentage may be 0), but must not
     * reach a submitted week: a 0% entry carries a category and project into the manday cost
     * report while contributing nothing, which reads as real work that took no time.
     *
     * @param  array<int, array{entry_date:string, percentage:float|string}>  $entries
     */
    private function assertNoBlankLines(array $entries): void
    {
        // Every offending day, not the first. Throwing inside the loop made a week with
        // three bad days cost three round trips: fix one, submit, be told about the next.
        $days = [];
        foreach ($entries as $e) {
            if ((float) $e['percentage'] <= 0) {
                $days[Carbon::parse($e['entry_date'])->toDateString()] = true;
            }
        }

        if ($days !== []) {
            throw ValidationException::withMessages([
                'submit' => array_map(
                    fn (string $date) => Carbon::parse($date)->format('D, j M').' has a line with no percentage — fill it in or remove it before submitting.',
                    array_keys($days),
                ),
            ]);
        }
    }

    /**
     * The same Category · Project · Sub-pillar must not appear twice on one day. The picker
     * greys out what a day already carries, but a stale tab or a hand-made request can still
     * send the pair, and two identical lines are impossible to tell apart once saved.
     *
     * @param  array<int, array<string, mixed>>  $entries
     */
    private function assertNoDuplicateLines(array $entries): void
    {
        $seen = [];
        $days = [];
        foreach ($entries as $e) {
            $date = Carbon::parse($e['entry_date'])->toDateString();
            $key = implode('|', [
                $date,
                $e['category_id'],
                $e['project_id'] ?? '',
                $e['sub_pillar_id'] ?? '',
            ]);

            if (isset($seen[$key])) {
                $days[$date] = true;
            }

            $seen[$key] = true;
        }

        if ($days !== []) {
            throw ValidationException::withMessages([
                'entries' => array_map(
                    fn (string $date) => Carbon::parse($date)->format('D, j M').' has the same work listed twice — put it on one line instead.',
                    array_keys($days),
                ),
            ]);
        }
    }

    /**
     * Blocks submission before the week is over (Timesheet::weekEndsOn()). Without this, a
     * staffer whose days-so-far already total 100% could submit mid-week.
     */
    private function assertWeekEnded(Carbon $weekStart): void
    {
        $endsOn = Timesheet::computeWeekEndsOn($weekStart);
        if (Carbon::now()->startOfDay()->lessThan($endsOn)) {
            throw ValidationException::withMessages([
                'submit' => 'This week is not over yet — submit becomes available on '.$endsOn->format('D, j M').'.',
            ]);
        }
    }

    /**
     * Every day that has entries must total exactly 100% (float tolerance). Empty days
     * are allowed. Throws a ValidationException keyed by the offending date.
     *
     * @param  array<int, array{entry_date:string, percentage:float}>  $entries
     */
    private function assertDayTotals(array $entries): void
    {
        $byDay = [];
        foreach ($entries as $e) {
            $byDay[$e['entry_date']] = ($byDay[$e['entry_date']] ?? 0) + (float) $e['percentage'];
        }

        $messages = [];
        foreach ($byDay as $date => $total) {
            // The TOT Saturday is a half day, so it is full at 50%; every other day at 100%.
            $capacity = DayCapacity::for($date);

            if (abs($total - $capacity) >= 0.01) {
                $shown = rtrim(rtrim(number_format($total, 2), '0'), '.');
                $want = rtrim(rtrim(number_format($capacity, 2), '0'), '.');
                $messages[] = Carbon::parse($date)->format('D, j M').' totals '.$shown.'% — that day must add up to '.$want.'% before submitting.';
            }
        }

        if ($messages !== []) {
            throw ValidationException::withMessages(['submit' => $messages]);
        }
    }

    /**
     * Entry dates must be today or earlier (D2 — you cannot have spent time you have not
     * spent), and no earlier than the backfill window (D3). Generated leave and holiday rows
     * bypass this: they are approved facts, not claims, and may legitimately sit in the future.
     *
     * @param  array<int, array<string, mixed>>  $entries
     */
    private function assertDatesInWindow(array $entries): void
    {
        $today = Carbon::now()->startOfDay();
        $earliest = Carbon::now()->startOfWeek()->subWeeks(self::BACKFILL_WEEKS);

        // Keyed by row index — that is what points at the offending line — and gathered
        // rather than thrown at the first one, so a week that reaches back too far is
        // reported in full instead of one day per attempt.
        $messages = [];
        foreach ($entries as $i => $e) {
            $date = Carbon::parse($e['entry_date'])->startOfDay();

            if ($date->greaterThan($today)) {
                $messages["entries.$i.entry_date"] = $date->format('D, j M').' has not happened yet.';
            }

            if ($date->lessThan($earliest)) {
                // No per-week override exists, so the message must not promise one.
                $messages["entries.$i.entry_date"] = $date->format('D, j M').' is closed — timesheets can only be edited for '.self::BACKFILL_WEEKS.' weeks back.';
            }
        }

        if ($messages !== []) {
            throw ValidationException::withMessages($messages);
        }
    }
}
