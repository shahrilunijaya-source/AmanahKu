<?php

declare(strict_types=1);

namespace App\Timesheet;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Project;
use App\Models\Timesheet;
use App\Models\TimesheetCategory;
use App\Models\TimesheetDay;
use App\Models\TimesheetEntry;
use App\Models\WorkItem;
use App\Support\HtmlSanitizer;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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
 * change" (one day or a few) is a genuinely different shape: the developer
 * calling it only knows about their own project, not the rest of the day, so
 * mergePartialIntoExisting() ADDS a changed day's rows onto whatever is already
 * stored for that day rather than replacing it (see that method's docblock for
 * why, and how a same-line correction still behaves like an update). That
 * merged full week is still what actually gets saved via the ordinary save()
 * path — so the controller and the tool agree on what "save" means (whole-week
 * replace), they just disagree on what "the changed day's rows" means before
 * they get there.
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

    public function __construct(private LockedDays $lockedDays, private WeekReconciler $reconciler, private DayRules $dayRules) {}

    /**
     * Save (or refresh) a week as a draft from a full per-day grid, replacing whatever
     * is currently stored for that week. Optionally submit one day ($submitDay, plus
     * $dayReason for a day with no lines) or every remaining working day up to today
     * ($submitNow) in the same call — see class docs and OPEN "QA / CR-03" for the
     * per-day rules this enforces: frozen days (submitted/approved, or beyond the
     * backdate window without an unlock) keep their stored lines, and a changed line on
     * a frozen day is refused.
     *
     * @param  array<int, array{entry_date:string, category_id:int, project_id?:?int, sub_pillar_id?:?int, percentage:float|int|string, description?:?string}>  $rawEntries  validated, not yet normalised
     * @return array{timesheet: Timesheet, entries: array<int, array<string, mixed>>, locked: array<string, mixed>}
     */
    public function save(
        Employee $employee,
        CarbonInterface|string $weekStart,
        array $rawEntries,
        ?string $weekLabel,
        bool $submitNow,
        ?array $dismissed = null,
        ?string $submitDay = null,
        ?string $dayReason = null,
    ): array {
        $weekStartCarbon = Carbon::parse($weekStart)->startOfDay();
        $today = Carbon::now()->startOfDay();

        $resolved = $this->resolveWeek($employee, $weekStartCarbon, $rawEntries);
        $entries = $resolved['entries'];
        $locked = $resolved['locked'];
        $userByDate = [];
        foreach ($resolved['userEntries'] as $e) {
            $userByDate[$e['entry_date']][] = $e;
        }

        $timesheet = Timesheet::firstOrNew([
            'employee_id' => $employee->id,
            'week_start' => $weekStartCarbon,
        ]);

        $existingDays = $timesheet->exists
            ? $timesheet->days()->get()->keyBy(fn (TimesheetDay $d) => $d->entry_date->toDateString())
            : collect();
        $storedRowsByDate = $this->storedEntryRowsByDate($timesheet);
        $earliestEditable = $this->dayRules->earliestEditable($today);

        // Every working day that is frozen for the staff — submitted/approved, or
        // beyond the backdate window with no unlock — either keeps its stored lines
        // (grid omitted it) or refuses a changed line (grid resent it differently).
        //
        // The backdate window only bites once this week's Timesheet row already
        // exists: the very first save of a week (catching up a whole week's grid in
        // one go, possibly Friday) is creating the draft, not editing an old one, so
        // nothing is "frozen" yet to protect. A second save against an existing draft
        // is what the window guards.
        $frozenMessages = [];
        foreach ($this->dayRules->weekWorkingDays($weekStartCarbon) as $iso) {
            $dayRow = $existingDays->get($iso);
            $lockedByStatus = $dayRow !== null && $dayRow->isLockedForStaff();
            $lockedByWindow = $timesheet->exists && Carbon::parse($iso)->lt($earliestEditable) && ($dayRow === null || $dayRow->unlocked_at === null);

            if (! $lockedByStatus && ! $lockedByWindow) {
                continue;
            }

            $storedLines = $storedRowsByDate[$iso] ?? [];

            if (array_key_exists($iso, $userByDate)
                && $this->dayRules->lineSignature($userByDate[$iso]) !== $this->dayRules->lineSignature($storedLines)) {
                $frozenMessages[] = $lockedByStatus
                    ? Carbon::parse($iso)->format('D, j M').' is submitted and locked — ask your manager to return it for correction.'
                    : Carbon::parse($iso)->format('D, j M').' is more than '.((int) config('manday.edit_window_working_days', 3)).' working days back — ask your manager to unlock it.';

                continue;
            }

            // Frozen and unchanged (or omitted from the grid entirely): the day is locked
            // solid — its stored lines are what get persisted, full stop. Anything the
            // reconciler generated for this date (e.g. a leave row backfilled by a later
            // approval) never overrides a day that's already submitted/approved or beyond
            // the edit window.
            $entries = array_values(array_filter($entries, fn (array $row) => $row['entry_date'] !== $iso));
            foreach ($storedLines as $row) {
                $entries[] = $row;
            }
        }

        if ($frozenMessages !== []) {
            throw ValidationException::withMessages(['entries' => $frozenMessages]);
        }

        // Days to mark submitted once the transaction commits: iso => zero_reason (or
        // null when the day carries real lines). Validated fully before anything is
        // persisted — a refused submit_day / submit_now must change nothing.
        $daysToSubmit = [];

        if ($submitDay !== null) {
            $iso = Carbon::parse($submitDay)->toDateString();
            $this->assertCanSubmitDay($iso, $today, $existingDays, $locked);

            $dayLines = self::linesForDate($entries, $iso);
            if ($dayLines === []) {
                if (! filled($dayReason)) {
                    throw ValidationException::withMessages([
                        'submit' => Carbon::parse($iso)->format('D, j M').' has no lines — give a reason (no allocation, training, offsite) or add a line.',
                    ]);
                }
                $daysToSubmit[$iso] = $dayReason;
            } else {
                // Totals and blank-line checks run against the day's FULL row set — user
                // lines plus a generated locked row (e.g. a half-day leave's 50%) — since
                // capacity is only reached once both are counted; "has no lines" above
                // stays user-lines-only, since a half-day leave alone must not count as
                // the staffer having logged anything.
                $fullDayLines = self::fullLinesForDate($entries, $iso);
                $this->assertNoBlankLines($fullDayLines);
                $this->assertDayTotals($fullDayLines);
                $daysToSubmit[$iso] = null;
            }
        } elseif ($submitNow) {
            $weekEnd = $weekStartCarbon->copy()->addDays(5);
            $upTo = $today->lt($weekEnd) ? $today : $weekEnd;

            $candidates = array_filter(
                $this->dayRules->weekWorkingDays($weekStartCarbon),
                function (string $iso) use ($upTo, $locked, $existingDays) {
                    if (Carbon::parse($iso)->gt($upTo)) {
                        return false;
                    }
                    if (($locked[$iso]['percentage'] ?? 0) >= DayCapacity::for($iso)) {
                        return false;
                    }
                    $status = $existingDays->get($iso)?->status;

                    return ! in_array($status, [TimesheetDay::STATUS_SUBMITTED, TimesheetDay::STATUS_APPROVED], true);
                }
            );

            abort_if($candidates === [], 422, 'Nothing left to submit this week.');

            $messages = [];
            foreach ($candidates as $iso) {
                $dayLines = self::linesForDate($entries, $iso);
                if ($dayLines === []) {
                    $messages[] = Carbon::parse($iso)->format('D, j M').' has no lines — submit it on its own with a reason, or add a line.';

                    continue;
                }
                $fullDayLines = self::fullLinesForDate($entries, $iso);
                $messages = [...$messages, ...$this->blankLineMessages($fullDayLines), ...$this->dayTotalMessages($fullDayLines)];
            }

            if ($messages !== []) {
                throw ValidationException::withMessages(['submit' => $messages]);
            }

            foreach ($candidates as $iso) {
                $daysToSubmit[$iso] = null;
            }
        }

        // Line-change audit: every working day whose user-line signature differs from
        // what is currently stored, {category_id, project_id, percentage} per line.
        $lineAudits = [];
        foreach ($this->dayRules->weekWorkingDays($weekStartCarbon) as $iso) {
            $oldLines = $storedRowsByDate[$iso] ?? [];
            $newLines = self::linesForDate($entries, $iso);

            if ($this->dayRules->lineSignature($oldLines) !== $this->dayRules->lineSignature($newLines)) {
                $lineAudits[$iso] = [
                    'old' => $oldLines === [] ? null : array_map([self::class, 'auditLine'], $oldLines),
                    'new' => $newLines === [] ? null : array_map([self::class, 'auditLine'], $newLines),
                ];
            }
        }

        DB::transaction(function () use ($timesheet, $weekLabel, $entries, $dismissed, $daysToSubmit, $existingDays, $lineAudits) {
            // Status is not set here — it is entirely derived from timesheet_days by
            // refreshStatusFromDays() below. A brand new row inserts with the column's
            // own 'draft' default.
            $timesheet->fill(['week_label' => $weekLabel])->save();

            // Null means "whoever called me does not know about dismissals" — the MCP
            // tool and the leave/holiday reconcile both save whole weeks without ever
            // seeing the capture grid's struck-off cards, and must not wipe them.
            if ($dismissed !== null) {
                $timesheet->dismissed_suggestions = $dismissed ?: null;
                $timesheet->save();
            }

            // The grid represents the entire week — replace, don't append.
            $timesheet->entries()->delete();
            foreach ($entries as $entry) {
                $timesheet->entries()->create($entry);
            }
            $timesheet->recomputeTotal();

            foreach ($lineAudits as $iso => $sides) {
                AuditLog::change($timesheet, "day.{$iso}.entries", $sides['old'], $sides['new']);
            }

            foreach ($daysToSubmit as $iso => $reason) {
                $dayRow = $existingDays->get($iso);
                $oldStatus = $dayRow?->status;

                $day = TimesheetDay::firstOrNew(['timesheet_id' => $timesheet->id, 'entry_date' => $iso]);
                $day->tenant_id = $timesheet->tenant_id;
                $day->status = TimesheetDay::STATUS_SUBMITTED;
                $day->submitted_at = now();
                $day->late = now()->gt($this->dayRules->deadlineFor(Carbon::parse($iso)));
                $day->resubmitted = $oldStatus === TimesheetDay::STATUS_RETURNED;
                $day->zero_reason = $reason;
                $day->save();

                AuditLog::change($timesheet, "day.{$iso}.status", $oldStatus, TimesheetDay::STATUS_SUBMITTED);
            }
        });

        $timesheet->refresh();
        $timesheet->refreshStatusFromDays();

        return ['timesheet' => $timesheet, 'entries' => $entries, 'locked' => $locked];
    }

    /**
     * A day may be submitted (via $submitDay) only when it has actually happened,
     * is not already accounted for by leave/holiday, and is not already submitted
     * or approved (a returned day may be resubmitted).
     *
     * @param  Collection<string, TimesheetDay>  $existingDays
     * @param  array<string, array{percentage: float}>  $locked
     */
    private function assertCanSubmitDay(string $iso, Carbon $today, Collection $existingDays, array $locked): void
    {
        $date = Carbon::parse($iso);

        if ($date->gt($today)) {
            throw ValidationException::withMessages(['submit' => $date->format('D, j M').' has not happened yet.']);
        }

        if (($locked[$iso]['percentage'] ?? 0) >= DayCapacity::for($iso)) {
            throw ValidationException::withMessages(['submit' => $date->format('D, j M').' is leave or a public holiday — nothing to submit.']);
        }

        $status = $existingDays->get($iso)?->status;
        if (in_array($status, [TimesheetDay::STATUS_SUBMITTED, TimesheetDay::STATUS_APPROVED], true)) {
            throw ValidationException::withMessages(['submit' => $date->format('D, j M').' has already been submitted.']);
        }
    }

    /**
     * The user-typed rows (never a generated locked row — those always carry a
     * 'source' key) for one date out of a resolved entries list.
     *
     * @param  array<int, array<string, mixed>>  $entries
     * @return array<int, array<string, mixed>>
     */
    private static function linesForDate(array $entries, string $iso): array
    {
        return array_values(array_filter(
            $entries,
            fn (array $e) => $e['entry_date'] === $iso && ! isset($e['source']),
        ));
    }

    /**
     * Every row for one date, user-typed AND generated (a half-day leave's locked 50%,
     * a shrunk Public Holiday row). Used for capacity checks — a half-day leave day
     * only reaches 100% by counting both — never for the "has this day got a real
     * line" check, which stays linesForDate()'s user-only definition.
     *
     * @param  array<int, array<string, mixed>>  $entries
     * @return array<int, array<string, mixed>>
     */
    private static function fullLinesForDate(array $entries, string $iso): array
    {
        return array_values(array_filter($entries, fn (array $e) => $e['entry_date'] === $iso));
    }

    /** @return array{category_id:int, project_id:?int, percentage:float} */
    private static function auditLine(array $line): array
    {
        return [
            'category_id' => (int) $line['category_id'],
            'project_id' => isset($line['project_id']) ? (int) $line['project_id'] : null,
            'percentage' => round((float) $line['percentage'], 2),
        ];
    }

    /**
     * The currently stored user-typed rows (source null) of $timesheet, shaped exactly
     * like normaliseEntries()'s output and grouped by date — ready either to compare
     * against the incoming grid, or to be spliced straight back into $entries when a
     * frozen day is omitted from it.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function storedEntryRowsByDate(Timesheet $timesheet): array
    {
        if (! $timesheet->exists) {
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
                'work_item_id' => $e->work_item_id,
                'project' => $e->project,
                'hours' => (float) $e->hours,
            ])
            ->groupBy('entry_date')
            ->map(fn ($rows) => $rows->all())
            ->all();
    }

    /**
     * Compute the FULL resulting week — locked-day filtering, the date-window and
     * in-week checks, normalisation, duplicate-line rejection, and the locked-row
     * merge — without writing anything. save() persists exactly what this returns;
     * SaveTimesheetDraftTool's preview renders exactly this too (fed the raw output
     * of mergePartialIntoExisting()), so there is exactly one place that decides
     * what "the resulting week" is, and preview and confirm can never disagree
     * about it.
     *
     * @param  array<int, array{entry_date:string, category_id:int, project_id?:?int, sub_pillar_id?:?int, percentage:float|int|string, description?:?string}>  $rawEntries  the full week's grid, validated, not yet normalised
     * @return array{entries: array<int, array<string, mixed>>, dropped: array<int, array<string, mixed>>, locked: array<string, mixed>, userEntries: array<int, array<string, mixed>>}
     */
    public function resolveWeek(Employee $employee, CarbonInterface|string $weekStart, array $rawEntries): array
    {
        $weekStartCarbon = Carbon::parse($weekStart)->startOfDay();

        // Every caller of resolveWeek() — the browser, the MCP tool, and the
        // leave/holiday reconcile — goes through this the same way. It used to be a
        // copy TimesheetController::store() ran on its own after validate() and
        // before calling into WeekWriter, which meant any new caller (the MCP tool,
        // when it was first built) could forget it entirely.
        $this->assertOwnedWorkItems($rawEntries, $employee);

        $locked = $this->lockedDays->forWeek($employee, $weekStartCarbon);

        // D4: a day locked to its full capacity by something other than a holiday (a
        // whole-day leave) is a fact HR owns — anything typed against it is wrong by
        // definition, so it is dropped. A half-day leave locks only 50%, leaving the
        // staffer to fill the other half, so their rows on a partially locked day are kept
        // and merged with the 50% leave row. A public holiday keeps typed rows too: the
        // generated Public Holiday row shrinks to the remainder instead (see
        // LockedDays::entryRows()). LockedDays::keepsTypedRows() is the one place this
        // decision is made.
        //
        // Dropped rows are collected (not just discarded) so the MCP preview can tell the
        // model which typed rows a locked day overrode, instead of the resulting week just
        // looking silently different from what was submitted.
        $dropped = [];
        $userEntries = array_filter(
            $rawEntries,
            function (array $e) use ($locked, &$dropped) {
                $date = Carbon::parse($e['entry_date']);
                $day = $locked[$date->toDateString()] ?? null;
                $keep = $this->lockedDays->keepsTypedRows($day, $date);

                if (! $keep) {
                    $dropped[] = $e;
                }

                return $keep;
            }
        );

        $this->assertDatesInWindow($userEntries);
        $this->assertDatesInWeek($weekStartCarbon, $userEntries);

        // Normalise first, then check for duplicates: normalisation is what nulls the
        // project on a standalone category, so two lines that differ only by a stray
        // project_id the category never uses are the same line by the time they are compared.
        $normalised = $this->normaliseEntries($userEntries);
        $this->assertNoDuplicateLines($normalised);

        $entries = $this->reconciler->mergeEntries($employee, $weekStartCarbon, $normalised);

        return ['entries' => $entries, 'dropped' => $dropped, 'locked' => $locked, 'userEntries' => $normalised];
    }

    /**
     * work_item_id arrives from the browser, so it is a trust boundary. An `exists` rule
     * is not enough here: model lookups in this app are not tenant-scoped, so a bare
     * existence check would happily accept another tenant's — or another colleague's —
     * card id. A foreign id would corrupt the prefill's "already logged" check, hand the
     * staffer a category read off somebody else's entry, and skew per-card figures.
     *
     * Accepted: a card in the active tenant that this employee owns or participates in —
     * the same membership rule BoardSuggestions applies. A row with no work_item_id at
     * all (a generated locked row, or a legacy draft saved before this column existed)
     * is not this check's business and passes through untouched.
     *
     * @param  array<int, array<string, mixed>>  $entries
     */
    private function assertOwnedWorkItems(array $entries, Employee $employee): void
    {
        $ids = collect($entries)->pluck('work_item_id')->filter()->map(fn ($id) => (int) $id)->unique();

        if ($ids->isEmpty()) {
            return;
        }

        $allowed = WorkItem::whereIn('id', $ids)
            ->where(fn ($q) => $q->where('employee_id', $employee->id)
                ->orWhereHas('participants', fn ($p) => $p->where('employees.id', $employee->id)))
            ->pluck('id')
            ->all();

        $problems = [];
        foreach ($entries as $i => $entry) {
            $id = $entry['work_item_id'] ?? null;
            if ($id !== null && ! in_array((int) $id, $allowed, true)) {
                $problems["entries.$i.work_item_id"] = 'That task is not yours.';
            }
        }

        if ($problems !== []) {
            throw ValidationException::withMessages($problems);
        }
    }

    /**
     * Compute the FULL resulting week from the currently stored draft's own rows
     * (source = null, i.e. never the generated locked rows) overlaid by a partial
     * change: every date present in $partialEntries ADDS its rows to whatever is
     * already stored for that date — it does not replace the day.
     *
     * A row that names the same line (category + project + sub-pillar, see
     * lineKey() — the same definition assertNoDuplicateLines() uses) as one
     * already stored for that date is an UPSERT, not a duplicate: the caller is
     * legitimately re-saving that exact line (typically to correct its
     * percentage), so the new row replaces the old one and nothing is
     * double-counted. A row naming a *different* line is purely additive — this
     * is the fix for the split-day bug: saving Tuesday as Amanahku 50% from one
     * project folder, then Tuesday as iGuaman 50% from another, now leaves both
     * rows in place instead of the second silently erasing the first.
     *
     * $replaceDates names dates that should still get the old whole-day replace
     * instead — the escape hatch for the one thing pure merging cannot do:
     * *removing* a line the caller never mentions. "Actually Tuesday was all
     * Amanahku, drop the iGuaman half" cannot be expressed by adding rows, since
     * the tool never learns the iGuaman line's key to subtract it — the caller
     * has to say "replace this day" instead. See SaveTimesheetDraftTool's
     * `replace_days` argument.
     *
     * This is deliberately a pure computation with no write — its output is what
     * gets handed to resolveWeek() (by both the MCP preview and save()) and, once
     * confirmed, to save() itself. There is exactly one place that decides what
     * "merge a partial change into a week" means.
     *
     * A week with no stored draft yet is treated as entirely empty, so the first
     * save from the tool is just the partial change on its own.
     *
     * @param  array<int, array{entry_date:string, category_id:int, project_id?:?int, sub_pillar_id?:?int, percentage:float|int|string, description?:?string}>  $partialEntries
     * @param  array<int, string>  $replaceDates  dates (any parseable format) to fully replace instead of merge
     * @return array<int, array<string, mixed>> raw entries, same shape as $partialEntries, ready for save()
     */
    public function mergePartialIntoExisting(Employee $employee, CarbonInterface|string $weekStart, array $partialEntries, array $replaceDates = []): array
    {
        $weekStartCarbon = Carbon::parse($weekStart)->startOfDay();
        $replaceDates = array_flip(array_map(fn ($d) => Carbon::parse($d)->toDateString(), $replaceDates));

        $byDate = [];
        foreach ($this->existingUserEntries($employee, $weekStartCarbon) as $row) {
            $byDate[$row['entry_date']][self::lineKey($row)] = $row;
        }

        $changedByDate = [];
        foreach ($partialEntries as $row) {
            $date = Carbon::parse($row['entry_date'])->toDateString();
            $changedByDate[$date][self::lineKey($row)] = $row;
        }

        foreach ($changedByDate as $date => $rows) {
            if (isset($replaceDates[$date])) {
                $byDate[$date] = $rows;
            } else {
                // array_merge on these string-keyed arrays overwrites a matching line
                // (upsert) and keeps everything else — the add-not-replace behaviour
                // described above.
                $byDate[$date] = array_merge($byDate[$date] ?? [], $rows);
            }
        }

        ksort($byDate);

        $out = [];
        foreach ($byDate as $rows) {
            foreach ($rows as $row) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * Refuse when merging $partialEntries into $employee's currently stored week
     * (via mergePartialIntoExisting(), same $replaceDates) would push any date
     * named in $partialEntries over its capacity (DayCapacity::for — the TOT
     * Saturday's 50%, 100% everywhere else).
     *
     * This is distinct from assertDayTotals(): that one demands a day hit
     * capacity EXACTLY, but only at submit time. This blocks going OVER, at
     * draft time, and only for SaveTimesheetDraftTool — a browser save always
     * replaces the whole day from the capture grid (which already keeps a day at
     * or under capacity client-side), so nothing in save()/resolveWeek() calls
     * this and the browser path is unaffected.
     *
     * Queries fresh each call rather than trusting numbers computed earlier, so
     * the same call from the tool's preview and its later confirm (which can be
     * up to ten minutes apart — see App\Mcp\PendingWrite) each see whatever is
     * actually stored at that moment.
     *
     * @param  array<int, array<string, mixed>>  $partialEntries
     * @param  array<int, string>  $replaceDates
     */
    public function assertMergeWithinCapacity(Employee $employee, CarbonInterface|string $weekStart, array $partialEntries, array $replaceDates = []): void
    {
        $weekStartCarbon = Carbon::parse($weekStart)->startOfDay();

        $priorTotals = [];
        foreach ($this->existingUserEntries($employee, $weekStartCarbon) as $row) {
            $priorTotals[$row['entry_date']] = ($priorTotals[$row['entry_date']] ?? 0.0) + (float) $row['percentage'];
        }

        $addedTotals = [];
        $touchedDates = [];
        foreach ($partialEntries as $row) {
            $date = Carbon::parse($row['entry_date'])->toDateString();
            $addedTotals[$date] = ($addedTotals[$date] ?? 0.0) + (float) $row['percentage'];
            $touchedDates[$date] = true;
        }

        $merged = $this->mergePartialIntoExisting($employee, $weekStartCarbon, $partialEntries, $replaceDates);
        $resultingTotals = [];
        foreach ($merged as $row) {
            $resultingTotals[$row['entry_date']] = ($resultingTotals[$row['entry_date']] ?? 0.0) + (float) $row['percentage'];
        }

        $messages = [];
        foreach (array_keys($touchedDates) as $date) {
            $capacity = DayCapacity::for($date);
            $resultingTotal = $resultingTotals[$date] ?? 0.0;

            if ($resultingTotal - $capacity > 0.01) {
                $messages[] = Carbon::parse($date)->format('D, j M').' already has '.
                    self::formatPercent($priorTotals[$date] ?? 0.0).'% stored; adding '.
                    self::formatPercent($addedTotals[$date]).'% would bring it to '.
                    self::formatPercent($resultingTotal).'%, over the '.self::formatPercent($capacity).'% capacity for that day.';
            }
        }

        if ($messages !== []) {
            throw ValidationException::withMessages(['entries' => $messages]);
        }
    }

    /**
     * The "same line" key assertNoDuplicateLines() rejects a repeat of, and
     * mergePartialIntoExisting() uses to decide an incoming row is a correction
     * (same key -> upsert) rather than an addition (new key -> kept alongside).
     * One definition, reused everywhere a line's identity matters: date +
     * category + project + sub-pillar.
     *
     * @param  array<string, mixed>  $entry
     */
    public static function lineKey(array $entry): string
    {
        return implode('|', [
            Carbon::parse($entry['entry_date'])->toDateString(),
            $entry['category_id'],
            $entry['project_id'] ?? '',
            $entry['sub_pillar_id'] ?? '',
            // Two different board cards are two different lines even when they share a
            // category and project — which they usually do, since most projects map to a
            // single category. Without this, a day with two cards from one project is
            // rejected as "the same work listed twice". A typed row carries no card and
            // keys exactly as it did before.
            $entry['work_item_id'] ?? '',
        ]);
    }

    /** Trims trailing zeros the way every percentage-in-a-message spot here wants. */
    private static function formatPercent(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2), '0'), '.');
    }

    /**
     * The stored draft's own rows for a week (never the generated locked rows,
     * which are always re-derived, not carried forward) — same source WeekReconciler::reconcile()
     * reads, in the same raw shape save() expects to receive.
     *
     * @return array<int, array<string, mixed>>
     */
    public function existingUserEntries(Employee $employee, CarbonInterface|string $weekStart): array
    {
        $weekStartCarbon = Carbon::parse($weekStart)->startOfDay();

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
                'work_item_id' => $e->work_item_id,
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
                'work_item_id' => $e['work_item_id'] ?? null,
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
        $messages = $this->blankLineMessages($entries);
        if ($messages !== []) {
            throw ValidationException::withMessages(['submit' => $messages]);
        }
    }

    /**
     * Same rule as assertNoBlankLines(), but returns the messages instead of throwing —
     * submit_now needs to gather every candidate day's problems before refusing the
     * whole batch in one go.
     *
     * @param  array<int, array{entry_date:string, percentage:float|string}>  $entries
     * @return array<int, string>
     */
    private function blankLineMessages(array $entries): array
    {
        // Every offending day, not the first. Throwing inside the loop made a week with
        // three bad days cost three round trips: fix one, submit, be told about the next.
        $days = [];
        foreach ($entries as $e) {
            if ((float) $e['percentage'] <= 0) {
                $days[Carbon::parse($e['entry_date'])->toDateString()] = true;
            }
        }

        return array_map(
            fn (string $date) => Carbon::parse($date)->format('D, j M').' has a line with no percentage — fill it in or remove it before submitting.',
            array_keys($days),
        );
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
            $key = self::lineKey($e);

            if (isset($seen[$key])) {
                $days[Carbon::parse($e['entry_date'])->toDateString()] = true;
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
     * Every day that has entries must total exactly 100% (float tolerance). Empty days
     * are allowed. Throws a ValidationException keyed by the offending date.
     *
     * @param  array<int, array{entry_date:string, percentage:float}>  $entries
     */
    private function assertDayTotals(array $entries): void
    {
        $messages = $this->dayTotalMessages($entries);
        if ($messages !== []) {
            throw ValidationException::withMessages(['submit' => $messages]);
        }
    }

    /**
     * Same rule as assertDayTotals(), but returns the messages instead of throwing —
     * see blankLineMessages() for why submit_now needs this shape.
     *
     * @param  array<int, array{entry_date:string, percentage:float}>  $entries
     * @return array<int, string>
     */
    private function dayTotalMessages(array $entries): array
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
                $messages[] = Carbon::parse($date)->format('D, j M').' totals '.self::formatPercent($total).'% — that day must add up to '.self::formatPercent($capacity).'% before submitting.';
            }
        }

        return $messages;
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

    /**
     * An entry_date must actually fall inside the week it is being filed under.
     * assertDatesInWindow() only checks a date against "today" and the backfill
     * window — nothing stopped a perfectly valid Monday-dated entry from being
     * saved into a *different* week's grid, silently corrupting that other week's
     * totals and day-capacity checks. assertDatesInWindow() does not receive
     * week_start today, so this is a separate assertion rather than a widened
     * signature.
     *
     * @param  array<int, array<string, mixed>>  $entries
     */
    private function assertDatesInWeek(CarbonInterface $weekStart, array $entries): void
    {
        $weekEnd = Carbon::parse($weekStart)->addDays(6);

        // Keyed by row index and gathered, not thrown at the first one — same reasoning
        // as assertDatesInWindow().
        $messages = [];
        foreach ($entries as $i => $e) {
            $date = Carbon::parse($e['entry_date'])->startOfDay();

            if ($date->lessThan($weekStart) || $date->greaterThan($weekEnd)) {
                $messages["entries.$i.entry_date"] = $date->format('D, j M').' is not in the week starting '.Carbon::parse($weekStart)->format('D, j M').'.';
            }
        }

        if ($messages !== []) {
            throw ValidationException::withMessages($messages);
        }
    }
}
