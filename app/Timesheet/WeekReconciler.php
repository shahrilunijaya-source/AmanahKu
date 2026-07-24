<?php

declare(strict_types=1);

namespace App\Timesheet;

use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Timesheet;
use App\Models\TimesheetEntry;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Keeps a stored timesheet week in step with the locked days HR owns (approved leave,
 * public holidays).
 *
 * The capture save path (TimesheetController::store) and leave approval
 * (LeaveController::applyApproval) both need the same "drop what the staffer typed on a
 * locked day, then lay down the generated On Leave / Public Holiday rows" merge. This is
 * the single home for it, so the two paths cannot drift. LockedDays stays the source of
 * truth for what a locked day looks like; this class only merges and persists.
 */
final class WeekReconciler
{
    public function __construct(private LockedDays $lockedDays) {}

    /**
     * Merge staffer-typed rows with the generated locked rows for a week, then append the
     * generated rows. A fully locked day (public holiday, whole-day leave) is a fact HR
     * owns, not a claim, so anything typed against it is dropped. A half-day leave locks
     * only 50%: the staffer still fills the other half, so their rows on a partly locked
     * day are kept and the day reaches 100% from the 50% leave plus those rows. Returns
     * entry arrays ready to persist.
     *
     * @param  array<int, array<string, mixed>>  $userRows  normalised, source=null rows
     * @return array<int, array<string, mixed>>
     */
    public function mergeEntries(Employee $employee, CarbonInterface|string $weekStart, array $userRows): array
    {
        $locked = $this->lockedDays->forWeek($employee, $weekStart);

        $kept = array_filter(
            $userRows,
            function (array $e) use ($locked) {
                $day = $locked[CarbonImmutable::parse($e['entry_date'])->toDateString()] ?? null;

                return $day === null || $day['percentage'] < 100;
            }
        );

        return array_merge(array_values($kept), $this->lockedDays->entryRows($employee, $weekStart));
    }

    /**
     * Push a freshly-approved leave request into every timesheet week already stored for
     * its employee, so a week saved BEFORE the leave was approved gains its On Leave rows
     * without waiting for a manual re-save. Returns how many stored weeks were reconciled
     * (for the caller's audit trail).
     *
     * Assumes the leave request is already 'approved' on the current DB connection, so
     * LockedDays::forWeek (which filters status = 'approved') sees it.
     */
    public function reconcileForLeave(LeaveRequest $leaveRequest): int
    {
        $employee = $leaveRequest->employee;

        if (! $employee) {
            return 0;
        }

        $firstWeek = CarbonImmutable::parse($leaveRequest->date_from)->startOfWeek();
        $lastWeek = CarbonImmutable::parse($leaveRequest->date_to)->startOfWeek();

        $reconciled = 0;

        for ($week = $firstWeek; $week->lessThanOrEqualTo($lastWeek); $week = $week->addWeek()) {
            // forWeek() is the sargable (week_start >= day AND < day+1) scope, so it survives
            // the sqlite "00:00:00" date-cast suffix that a plain equality would miss.
            $timesheet = Timesheet::forWeek($week)->where('employee_id', $employee->id)->first();

            if ($timesheet && $this->reconcile($timesheet)) {
                $reconciled++;
            }
        }

        return $reconciled;
    }

    /**
     * Rebuild one stored week's entries against the current locked days and persist it.
     * Returns true when the week was reconciled, false when it was skipped.
     *
     * Draft and submitted weeks are reconciled: a submitted week is the whole point of this
     * class, since a week submitted before the leave was approved would otherwise silently
     * disagree with the approved leave until someone happened to re-save it. Reconciling
     * keeps every locked day at 100% On Leave, so a valid submission stays valid and the
     * status is left untouched. Terminal weeks are left alone: a 'rejected' week is dead,
     * and an 'approved' week is a decided figure that must not be mutated behind a decision
     * (no flow issues timesheet-approved today, so this is a guard, not a live path).
     */
    public function reconcile(Timesheet $timesheet): bool
    {
        if (! in_array($timesheet->status, ['draft', 'submitted'], true)) {
            return false;
        }

        // Resolve via the model (typed Employee) rather than the untyped relation accessor.
        $employee = Employee::find($timesheet->employee_id);

        if (! $employee) {
            return false;
        }

        // Only the staffer-typed rows (source = null) carry forward; the generated locked
        // rows are re-derived from LockedDays, never copied, so they can never go stale.
        // Queried through the model (not the relation) so the row type stays TimesheetEntry.
        $userRows = TimesheetEntry::where('timesheet_id', $timesheet->id)
            ->whereNull('source')
            ->get()
            ->map(fn (TimesheetEntry $e) => [
                'entry_date' => CarbonImmutable::parse($e->entry_date)->toDateString(),
                'category_id' => $e->category_id,
                'project_id' => $e->project_id,
                'sub_pillar_id' => $e->sub_pillar_id,
                'percentage' => (float) $e->percentage,
                'description' => $e->description,
                'project' => $e->project,
                'hours' => (float) $e->hours,
            ])
            ->all();

        $entries = $this->mergeEntries($employee, $timesheet->week_start, $userRows);

        DB::transaction(function () use ($timesheet, $entries) {
            // The merge is authoritative for the whole week — replace, don't append.
            $timesheet->entries()->delete();
            foreach ($entries as $entry) {
                $timesheet->entries()->create($entry);
            }
            $timesheet->recomputeTotal();
        });

        return true;
    }
}
