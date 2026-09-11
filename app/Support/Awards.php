<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\BirthdayWishReaction;
use App\Models\KnowledgeEntry;
use App\Models\KnowledgeReaction;
use App\Models\LeaveRequest;
use App\Models\Timesheet;
use App\Models\TimesheetDay;
use App\Models\TimesheetEntry;
use App\Models\TotComment;
use App\Models\TotReaction;
use App\Models\TotSession;
use App\Models\WorkItem;
use App\Tenancy\CurrentTenant;
use App\Timesheet\DayRules;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * CR-14a: one computation method per award key, called by `awards:freeze` for the tenant
 * `App\Tenancy\CurrentTenant` currently holds. Every award below is docs/specs/CR-14.md's
 * "simplest reading" where the spec leaves the exact rule open (see docs/build/OPEN.md).
 *
 * Card-based awards (done_and_dusted, deadline_who, chief_firefighter, not_my_task,
 * zero_overdue) all read the same exclusion set (docs/specs/date-calendar-rules.md rule 7,
 * CR-14 rule 4): type `event`, a non-null `source` (CR-19/CR-34 system cards), the
 * `recurring`/`system` labels, or a `recurring_task_occurrences` row. `WorkItem`'s own
 * `ParentOnly` global scope already keeps subtasks out of every query here — ponytail: not
 * special-cased, no acceptance test exercises a subtask completion, revisit if one ever does.
 *
 * "Completed this month" reads the first `audit_logs` row for that card with
 * `field = 'status'`, `new_value = '"done"'` (the board move's audited field-change),
 * falling back to `done_at` only when no such row exists (a card created already done).
 * Reopen + redo writes further audit rows but the FIRST one is what is read, so a card done
 * once and reopened/redone never counts twice and never moves months.
 */
final class Awards
{
    /** Spec list order — also the order rules 9/10 resolve in. */
    public const KEYS = [
        'beating_the_traffic', 'never_late', 'always_here', 'clockwork_royalty', 'timesheet_done',
        'billable', 'deadline_who', 'zero_overdue', 'chief_firefighter', 'done_and_dusted', 'not_my_task',
        'mic_drop_mentor', 'question_department', 'walking_wikipedia', 'chief_hype_officer',
    ];

    public function __construct(private CurrentTenant $tenant, private DayRules $dayRules) {}

    /**
     * Every award's values for the given month, for the tenant `CurrentTenant` currently
     * holds. Only people eligible for an award (its own floor rule met) get a row.
     *
     * @return array<string, array<int, array{value: float, label: string}>> award_key => employee_id => value/label
     */
    public function compute(Carbon $month): array
    {
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();

        $cards = $this->creditableCards();

        return [
            'beating_the_traffic' => $this->beatingTheTraffic($start, $end),
            'never_late' => $this->neverLate($start, $end),
            'always_here' => $this->alwaysHere($start, $end),
            'clockwork_royalty' => $this->clockworkRoyalty($start, $end),
            'timesheet_done' => $this->timesheetDone($start, $end),
            'billable' => $this->billable($month, $start, $end),
            'deadline_who' => $this->deadlineWho($cards, $start, $end),
            'zero_overdue' => $this->zeroOverdue($cards, $start, $end),
            'chief_firefighter' => $this->chiefFirefighter($cards, $start, $end),
            'done_and_dusted' => $this->doneAndDusted($cards, $start, $end),
            'not_my_task' => $this->notMyTask($cards, $start, $end),
            'mic_drop_mentor' => $this->micDropMentor($start, $end),
            'question_department' => $this->questionDepartment($start, $end),
            'walking_wikipedia' => $this->walkingWikipedia($start, $end),
            'chief_hype_officer' => $this->chiefHypeOfficer($start, $end),
        ];
    }

    // ── Card-based awards ──────────────────────────────────────────────────────────────

    /**
     * Public since S28 (CR-22 Amanahku Wrapped): the same exclusion set (events, system/
     * recurring/auto-closed cards) and completed-at derivation this class uses for the
     * card-based awards is exactly what Wrapped's company/personal card-count and
     * high-priority numbers must reuse, rather than re-deriving it. `created_at` is added
     * for Wrapped's "high-priority cards created that month" figure; every existing
     * caller in this class ignores the new key.
     *
     * @return Collection<int, object{id:int, employee_id:?int, priority:string, due_at:?Carbon, completed_at:?Carbon, created_at:?Carbon, helpers:list<int>}>
     */
    public function creditableCards(): Collection
    {
        $tenantId = $this->tenant->id();

        $occurrenceCardIds = DB::table('recurring_task_occurrences')
            ->where('tenant_id', $tenantId)->whereNotNull('work_item_id')->pluck('work_item_id')->all();

        $firstDone = AuditLog::query()
            ->where('tenant_id', $tenantId)
            ->where('subject_type', WorkItem::class)
            ->where('field', 'status')
            ->where('new_value', json_encode('done'))
            ->orderBy('created_at')
            ->get(['subject_id', 'created_at'])
            ->groupBy('subject_id')
            ->map(fn (Collection $rows) => Carbon::parse($rows->first()->created_at));

        $helpers = DB::table('work_item_participant')
            ->where('role', 'helper')
            ->get(['work_item_id', 'employee_id'])
            ->groupBy('work_item_id')
            ->map(fn (Collection $rows) => $rows->pluck('employee_id')->all());

        return WorkItem::where('tenant_id', $tenantId)->get()
            ->reject(fn (WorkItem $card) => $card->type === 'event'
                || $card->source !== null
                || array_intersect($card->labels ?? [], ['recurring', 'system']) !== []
                || in_array($card->id, $occurrenceCardIds, true)
                // CR-19: an auto-closed card (any trigger, not only the system-labelled
                // ones above) never earns an award either.
                || $card->auto_closed_at !== null)
            ->map(function (WorkItem $card) use ($firstDone, $helpers) {
                $completedAt = $firstDone->get($card->id)
                    ?? ($card->status === 'done' ? $card->done_at : null);

                return (object) [
                    'id' => $card->id,
                    'employee_id' => $card->employee_id,
                    'priority' => $card->priority,
                    'due_at' => $card->due_at,
                    'completed_at' => $completedAt,
                    'created_at' => $card->created_at,
                    'helpers' => $helpers->get($card->id, []),
                ];
            });
    }

    /** @return array<int, array{value: float, label: string}> */
    private function doneAndDusted(Collection $cards, Carbon $start, Carbon $end): array
    {
        $counts = $cards
            ->filter(fn ($c) => $c->employee_id && $c->completed_at?->between($start, $end))
            ->countBy('employee_id');

        return $this->countRows($counts, fn ($n) => "{$n} card".($n === 1 ? '' : 's').' finished this month');
    }

    /** @return array<int, array{value: float, label: string}> */
    private function deadlineWho(Collection $cards, Carbon $start, Carbon $end): array
    {
        $counts = $cards
            ->filter(fn ($c) => $c->employee_id && $c->due_at && $c->completed_at?->between($start, $end)
                && $c->completed_at->lte($c->due_at->copy()->endOfDay()))
            ->countBy('employee_id');

        return $this->countRows($counts, fn ($n) => "{$n} card".($n === 1 ? '' : 's').' finished before the due date');
    }

    /** @return array<int, array{value: float, label: string}> */
    private function chiefFirefighter(Collection $cards, Carbon $start, Carbon $end): array
    {
        $counts = $cards
            ->filter(fn ($c) => $c->employee_id && $c->priority === 'high' && $c->completed_at?->between($start, $end))
            ->countBy('employee_id');

        return $this->countRows($counts, fn ($n) => "{$n} high-priority card".($n === 1 ? '' : 's').' put out this month');
    }

    /** @return array<int, array{value: float, label: string}> */
    private function notMyTask(Collection $cards, Carbon $start, Carbon $end): array
    {
        $counts = [];
        foreach ($cards as $card) {
            if (! $card->completed_at?->between($start, $end)) {
                continue;
            }
            foreach ($card->helpers as $helperId) {
                $counts[$helperId] = ($counts[$helperId] ?? 0) + 1;
            }
        }

        return $this->countRows(collect($counts), fn ($n) => "helped finish {$n} card".($n === 1 ? '' : 's').' this month');
    }

    /**
     * Needs at least 5 assigned cards due in the month, none overdue. Freeze always runs
     * on the month's last day, and `due_at` is always inside the month here, so a card
     * still open at freeze is overdue by definition — no separate "not yet due" case to
     * weigh against a freeze timestamp.
     *
     * @return array<int, array{value: float, label: string}>
     */
    private function zeroOverdue(Collection $cards, Carbon $start, Carbon $end): array
    {
        $rows = [];
        foreach ($cards->filter(fn ($c) => $c->employee_id && $c->due_at?->between($start, $end))->groupBy('employee_id') as $employeeId => $owned) {
            if ($owned->count() < 5) {
                continue;
            }
            $late = $owned->first(fn ($c) => $c->completed_at
                ? $c->completed_at->gt($c->due_at->copy()->endOfDay())
                : true);
            if ($late) {
                continue;
            }
            $rows[$employeeId] = ['value' => (float) $owned->count(), 'label' => $owned->count().' cards, none overdue'];
        }

        return $rows;
    }

    // ── Attendance-based awards ────────────────────────────────────────────────────────

    /**
     * `date`/`entry_date` columns are declared DATE but sqlite (the test driver) stores
     * whatever string Eloquent's cast writes, which carries a midnight time suffix —
     * comparing that against a bare `Y-m-d` upper bound with `whereBetween` silently drops
     * the month's last day (the string sorts after its own date-only bound). `whereDate()`
     * normalises both sides through SQL's `date()` function, so it works the same on
     * sqlite and MySQL.
     */
    private function attendanceInMonth(Carbon $start, Carbon $end, ?string $type = 'standard'): Collection
    {
        return AttendanceRecord::where('tenant_id', $this->tenant->id())
            ->when($type !== null, fn ($q) => $q->where('type', $type))
            ->whereDate('date', '>=', $start->toDateString())
            ->whereDate('date', '<=', $end->toDateString())
            ->orderBy('date')
            ->get();
    }

    /** Median of clock_in - expected_start (minutes), lowest wins. @return array<int, array{value: float, label: string}> */
    private function beatingTheTraffic(Carbon $start, Carbon $end): array
    {
        $rows = [];
        foreach ($this->attendanceInMonth($start, $end)->groupBy('employee_id') as $employeeId => $records) {
            $diffs = $records->map(fn ($r) => $this->minutesDiff($r->expected_start, $r->clock_in))
                ->filter(fn ($v) => $v !== null)->sort()->values();
            if ($diffs->isEmpty()) {
                continue;
            }
            $median = $this->median($diffs->all());
            $rows[$employeeId] = ['value' => $median, 'label' => sprintf('%s minutes %s than expected, typically', abs(round($median)), $median <= 0 ? 'earlier' : 'later')];
        }

        return $rows;
    }

    /**
     * CR-14 rule 5: approved leave and non-working days are neutral for every attendance
     * award. Per employee, the ISO dates inside the month covered by an approved leave row.
     *
     * @return array<int, list<string>>
     */
    private function approvedLeaveDays(Carbon $start, Carbon $end): array
    {
        $days = [];
        $rows = LeaveRequest::where('tenant_id', $this->tenant->id())
            ->where('status', 'approved')
            ->whereDate('date_from', '<=', $end->toDateString())
            ->whereDate('date_to', '>=', $start->toDateString())
            ->get(['employee_id', 'date_from', 'date_to']);

        foreach ($rows as $leave) {
            $from = Carbon::parse($leave->date_from)->max($start);
            $to = Carbon::parse($leave->date_to)->min($end);
            for ($day = $from->copy()->startOfDay(); $day->lte($to); $day->addDay()) {
                $days[$leave->employee_id][] = $day->toDateString();
            }
        }

        return $days;
    }

    /** The month's working days minus the person's approved leave days (rule 5). @return list<string> */
    private function requiredDays(array $workingDays, array $leaveDays, int $employeeId): array
    {
        return array_values(array_diff($workingDays, $leaveDays[$employeeId] ?? []));
    }

    /** Lateness is a property of any clock-in, office or home (QA F3: no longer standard-only). @return array<int, array{value: float, label: string}> */
    private function neverLate(Carbon $start, Carbon $end): array
    {
        $rows = [];
        foreach ($this->attendanceInMonth($start, $end, null)->groupBy('employee_id') as $employeeId => $records) {
            if ($records->contains(fn ($r) => $r->status === 'late')) {
                continue;
            }
            $rows[$employeeId] = ['value' => (float) $records->count(), 'label' => $records->count().' days, never late'];
        }

        return $rows;
    }

    /**
     * A complete clock-in (office, client or home) on every working day the person was
     * not on approved leave (rule 5), and no incomplete shift: a clock-in with no clock-out
     * on a day that is over is a gap, the same reading as the dashboard's month summary.
     *
     * @return array<int, array{value: float, label: string}>
     */
    private function alwaysHere(Carbon $start, Carbon $end): array
    {
        $workingDays = $this->workingDaysBetween($start, $end);
        if ($workingDays === []) {
            return [];
        }
        $leave = $this->approvedLeaveDays($start, $end);
        $rows = [];
        foreach ($this->attendanceInMonth($start, $end, null)->groupBy('employee_id') as $employeeId => $records) {
            $required = $this->requiredDays($workingDays, $leave, (int) $employeeId);
            if ($required === []) {
                continue;
            }
            $incomplete = $records->contains(fn ($r) => $r->clock_in !== null && $r->clock_out === null);
            $present = $records->filter(fn ($r) => $r->clock_in !== null)
                ->pluck('date')->map(fn ($d) => Carbon::parse($d)->toDateString())->unique();
            if (! $incomplete && collect($required)->diff($present)->isEmpty()) {
                $rows[$employeeId] = ['value' => (float) count($required), 'label' => 'present every working day this month'];
            }
        }

        return $rows;
    }

    /**
     * Longest run of on-time working days. Non-working days and approved leave days are
     * neutral (rule 5): the run continues across them. A late day, or a working day with
     * no clock-in, breaks it.
     *
     * @return array<int, array{value: float, label: string}>
     */
    private function clockworkRoyalty(Carbon $start, Carbon $end): array
    {
        $workingDays = $this->workingDaysBetween($start, $end);
        $leave = $this->approvedLeaveDays($start, $end);
        $rows = [];
        foreach ($this->attendanceInMonth($start, $end, null)->groupBy('employee_id') as $employeeId => $records) {
            $byDate = $records->keyBy(fn ($r) => Carbon::parse($r->date)->toDateString());
            $onLeave = $leave[$employeeId] ?? [];
            $best = 0;
            $current = 0;
            foreach ($workingDays as $date) {
                if (in_array($date, $onLeave, true)) {
                    continue;
                }
                $record = $byDate->get($date);
                if ($record === null || $record->clock_in === null || $record->status === 'late') {
                    $current = 0;

                    continue;
                }
                $current++;
                $best = max($best, $current);
            }
            if ($best >= 1) {
                $rows[$employeeId] = ['value' => (float) $best, 'label' => "{$best} on-time days in a row"];
            }
        }

        return $rows;
    }

    /** Every working day submitted on time. @return array<int, array{value: float, label: string}> */
    private function timesheetDone(Carbon $start, Carbon $end): array
    {
        $workingDays = $this->workingDaysBetween($start, $end);
        if ($workingDays === []) {
            return [];
        }

        $rows = [];
        $leave = $this->approvedLeaveDays($start, $end);
        $employeeByTimesheet = Timesheet::where('tenant_id', $this->tenant->id())->pluck('employee_id', 'id');
        $days = TimesheetDay::where('tenant_id', $this->tenant->id())
            ->whereDate('entry_date', '>=', $start->toDateString())
            ->whereDate('entry_date', '<=', $end->toDateString())
            ->get()
            ->groupBy(fn ($d) => $employeeByTimesheet->get($d->timesheet_id));

        foreach ($days as $employeeId => $entries) {
            $compliant = $entries->filter(fn ($d) => $d->late === false && $d->status === TimesheetDay::STATUS_APPROVED)
                ->pluck('entry_date')->map(fn ($d) => Carbon::parse($d)->toDateString())->unique();
            $required = $this->requiredDays($workingDays, $leave, (int) $employeeId);
            if ($required !== [] && collect($required)->diff($compliant)->isEmpty()) {
                $rows[$employeeId] = ['value' => (float) count($required), 'label' => 'every working day submitted on time'];
            }
        }

        return $rows;
    }

    // ── Billable ───────────────────────────────────────────────────────────────────────

    /** @return array<int, array{value: float, label: string}> */
    private function billable(Carbon $month, Carbon $start, Carbon $end): array
    {
        $freezeInstant = $end->copy()->setTime(23, 59, 0);
        $sums = [];

        $entries = TimesheetEntry::where('tenant_id', $this->tenant->id())
            ->whereHas('timesheet', fn ($q) => $q->where('status', 'approved')->where('decided_at', '<=', $freezeInstant))
            ->whereHas('projectRef', fn ($q) => $q->whereNotNull('client'))
            ->with('timesheet:id,employee_id,decided_at')
            ->get();

        foreach ($entries as $entry) {
            $sheet = $entry->timesheet;
            $entryMonth = Carbon::parse($entry->entry_date)->startOfMonth();
            $entryMonthFreeze = $entryMonth->copy()->endOfMonth()->setTime(23, 59, 0);
            $decidedAt = Carbon::parse($sheet->decided_at);
            $attributedMonth = $decidedAt->lte($entryMonthFreeze) ? $entryMonth : $decidedAt->copy()->startOfMonth();

            if (! $attributedMonth->isSameMonth($month)) {
                continue;
            }
            $sums[$sheet->employee_id] = ($sums[$sheet->employee_id] ?? 0) + (float) $entry->hours;
        }

        $rows = [];
        foreach ($sums as $employeeId => $hours) {
            // QA F1: zero approved hours is no data, not a value to publish.
            if ($hours <= 0) {
                continue;
            }
            $rows[$employeeId] = ['value' => round($hours, 2), 'label' => round($hours, 2).' billable hours approved'];
        }

        return $rows;
    }

    // ── TOT / Knowledge Bank / birthday-reaction awards ───────────────────────────────

    /** @return array<int, array{value: float, label: string}> */
    private function micDropMentor(Carbon $start, Carbon $end): array
    {
        $tenantId = $this->tenant->id();
        $sessionIds = TotSession::where('tenant_id', $tenantId)
            ->where('year', $start->year)->where('month', $start->month)
            ->get()
            ->keyBy('id');

        // QA F6: a team session credits every presenter, not only the last one listed.
        $presenterMap = [];
        foreach ($sessionIds as $session) {
            $presenterMap[$session->id] = $session->presenterList()->pluck('id')->all();
        }

        $counts = [];
        foreach (TotReaction::where('tenant_id', $tenantId)->whereIn('session_id', $sessionIds->keys())->get() as $reaction) {
            foreach ($presenterMap[$reaction->session_id] ?? [] as $presenterId) {
                $counts[$presenterId] = ($counts[$presenterId] ?? 0) + 1;
            }
        }
        $counts = collect($counts);

        return $this->countRows($counts, fn ($n) => "{$n} reaction".($n === 1 ? '' : 's').' on sessions presented this month');
    }

    /** @return array<int, array{value: float, label: string}> */
    private function questionDepartment(Carbon $start, Carbon $end): array
    {
        $counts = TotComment::where('tenant_id', $this->tenant->id())
            ->whereBetween('created_at', [$start, $end])
            ->get()
            ->groupBy('employee_id')
            ->map(fn (Collection $rows) => $rows->pluck('session_id')->unique()->count());

        return $this->countRows($counts, fn ($n) => "questions in {$n} distinct session".($n === 1 ? '' : 's'));
    }

    /** @return array<int, array{value: float, label: string}> */
    private function walkingWikipedia(Carbon $start, Carbon $end): array
    {
        $counts = KnowledgeEntry::where('tenant_id', $this->tenant->id())
            ->whereBetween('created_at', [$start, $end])
            ->get()->countBy('employee_id');

        return $this->countRows($counts, fn ($n) => "{$n} Knowledge Bank entr".($n === 1 ? 'y' : 'ies').' added this month');
    }

    /** Distinct colleagues reacted to, across TOT/Knowledge/birthday reactions. @return array<int, array{value: float, label: string}> */
    private function chiefHypeOfficer(Carbon $start, Carbon $end): array
    {
        $tenantId = $this->tenant->id();
        $recipients = [];

        foreach (BirthdayWishReaction::where('tenant_id', $tenantId)->whereBetween('created_at', [$start, $end])->with('wish:id,employee_id')->get() as $reaction) {
            if ($reaction->wish) {
                $recipients[$reaction->employee_id][] = $reaction->wish->employee_id;
            }
        }
        $sessionOwner = TotSession::where('tenant_id', $tenantId)->get()->keyBy('id')
            ->map(fn (TotSession $s) => $s->presenterList()->first()?->id);
        foreach (TotReaction::where('tenant_id', $tenantId)->whereBetween('created_at', [$start, $end])->get() as $reaction) {
            $owner = $sessionOwner->get($reaction->session_id);
            if ($owner) {
                $recipients[$reaction->employee_id][] = $owner;
            }
        }
        foreach (KnowledgeReaction::where('tenant_id', $tenantId)->whereBetween('created_at', [$start, $end])->with('entry:id,employee_id')->get() as $reaction) {
            if ($reaction->entry) {
                $recipients[$reaction->employee_id][] = $reaction->entry->employee_id;
            }
        }

        $rows = [];
        foreach ($recipients as $employeeId => $ids) {
            $distinct = collect($ids)->filter(fn ($id) => $id !== $employeeId)->unique();
            if ($distinct->isNotEmpty()) {
                $rows[$employeeId] = ['value' => (float) $distinct->count(), 'label' => 'hyped up '.$distinct->count().' colleague'.($distinct->count() === 1 ? '' : 's').' this month'];
            }
        }

        return $rows;
    }

    // ── Small shared helpers ──────────────────────────────────────────────────────────

    /** @return array<int, array{value: float, label: string}> */
    private function countRows(Collection $counts, callable $label): array
    {
        $rows = [];
        foreach ($counts as $employeeId => $count) {
            if ($count > 0) {
                $rows[(int) $employeeId] = ['value' => (float) $count, 'label' => $label((int) $count)];
            }
        }

        return $rows;
    }

    /** clock_in minus expected_start, in minutes. Negative = early, positive = late. */
    private function minutesDiff(?string $expected, ?string $actual): ?float
    {
        if (! $expected || ! $actual) {
            return null;
        }

        $base = '2000-01-01 ';

        return (Carbon::parse($base.$actual)->getTimestamp() - Carbon::parse($base.$expected)->getTimestamp()) / 60;
    }

    /** @param  list<float>  $sorted */
    private function median(array $sorted): float
    {
        $count = count($sorted);
        $mid = intdiv($count, 2);

        return $count % 2 === 0 ? ($sorted[$mid - 1] + $sorted[$mid]) / 2 : $sorted[$mid];
    }

    /** @return list<string> ISO dates */
    private function workingDaysBetween(Carbon $start, Carbon $end): array
    {
        $days = [];
        $day = $start->copy();
        while ($day->lte($end)) {
            if ($this->dayRules->isWorkingDay($day)) {
                $days[] = $day->toDateString();
            }
            $day->addDay();
        }

        return $days;
    }
}
