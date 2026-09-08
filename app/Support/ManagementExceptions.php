<?php

namespace App\Support;

use App\Models\AttendanceIncident;
use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\ManagementMeetingSettings;
use App\Models\Scopes\ParentOnly;
use App\Models\Shift;
use App\Models\WorkItem;
use Illuminate\Support\Carbon;

/**
 * CR-17: the lateness-today and overdue-by-owner figures shown on the management
 * dashboard band and on the dedicated /app/management/exceptions page. Both callers
 * read this one service so the two views can never disagree.
 */
class ManagementExceptions
{
    /**
     * One row per active employee who is worth showing on the lateness panel today:
     * skips anyone on approved leave or clocked in as wfh/client (not shown at all),
     * shows everyone else either "Not clocked in", "Unverified" (inside an HR
     * incident window), "On time" or "Late {h}h{mm}m" (no grace).
     *
     * @param  list<int>|null  $employeeIds  null = every active employee (company-wide)
     * @return list<array{employee_id:int,name:string,status_en:string,status_ms:string}>
     */
    public function lateness(?array $employeeIds): array
    {
        $today = Carbon::now()->toDateString();

        $employees = Employee::query()->active()
            ->when($employeeIds !== null, fn ($q) => $q->whereIn('id', $employeeIds))
            ->get();

        if ($employees->isEmpty()) {
            return [];
        }

        $ids = $employees->pluck('id')->all();

        $onLeaveIds = LeaveRequest::whereIn('employee_id', $ids)
            ->where('status', 'approved')
            ->whereDate('date_from', '<=', $today)
            ->whereDate('date_to', '>=', $today)
            ->pluck('employee_id')->all();

        $records = AttendanceRecord::whereIn('employee_id', $ids)->onDate($today)->get()->keyBy('employee_id');

        $confirmedShiftIds = Shift::whereIn('employee_id', $ids)
            ->whereDate('date', $today)
            ->where('status', 'confirmed')
            ->pluck('employee_id')->all();

        $incidents = AttendanceIncident::where('starts_at', '<=', $today.' 23:59:59')
            ->where('ends_at', '>=', $today.' 00:00:00')
            ->get();

        $rows = [];

        foreach ($employees as $employee) {
            if (in_array($employee->id, $onLeaveIds, true)) {
                continue;
            }

            $record = $records->get($employee->id);

            if ($record && in_array($record->type, ['wfh', 'client'], true)) {
                continue;
            }

            $clockIn = $record?->clock_in;

            if ($clockIn === null) {
                $rows[] = $this->row($employee, 'Not clocked in', 'Belum log masuk');

                continue;
            }

            $clockInAt = Carbon::parse($today.' '.$clockIn);
            $inIncident = $incidents->contains(fn (AttendanceIncident $w) => $clockInAt->betweenIncluded($w->starts_at, $w->ends_at));

            if ($inIncident) {
                $rows[] = $this->row($employee, 'Unverified', 'Tidak disahkan');

                continue;
            }

            // A confirmed shift is a pre-approved flexi start: whenever they clocked in
            // against it, they are on time. A merely scheduled (not confirmed) shift is not.
            if (in_array($employee->id, $confirmedShiftIds, true)) {
                $rows[] = $this->row($employee, 'On time', 'Tepat masa');

                continue;
            }

            $expected = $record->expected_start ?? '09:00:00';

            if ($clockIn <= $expected) {
                $rows[] = $this->row($employee, 'On time', 'Tepat masa');

                continue;
            }

            $minutes = Carbon::parse($today.' '.$expected)->diffInMinutes($clockInAt, true);
            $h = intdiv($minutes, 60);
            $m = $minutes % 60;
            $rows[] = $this->row($employee, sprintf('Late %dh%02dm', $h, $m), sprintf('Lewat %dj%02dm', $h, $m));
        }

        return $rows;
    }

    /**
     * @return array{employee_id:int,name:string,status_en:string,status_ms:string}
     */
    private function row(Employee $employee, string $en, string $ms): array
    {
        return ['employee_id' => $employee->id, 'name' => $employee->display_name, 'status_en' => $en, 'status_ms' => $ms];
    }

    /**
     * Overdue T.A.A. cards (including subtasks), grouped strictly by the card's Primary
     * Owner (`employee_id`) — helpers and reviewers never pull a card into their group.
     * Excludes events (date-calendar-rules.md rule 7), cancelled and done cards.
     *
     * @param  list<int>|null  $employeeIds  null = every owner (company-wide)
     * @return list<array{owner_id:int,owner_name:string,cards:list<array{id:int,title:string,days_overdue:int,owner_reports_to_id:int|null,pm_id:int|null}>}>
     */
    public function overdue(?array $employeeIds): array
    {
        $today = Carbon::now()->startOfDay();
        $now = Carbon::now();

        // CR-34: a management_meeting card is overdue from the tenant's meeting time on its
        // own due date onward ("0 days overdue" that evening), not only from the next day
        // like every other card. Resolved once per call, never for a card outside today.
        $meetingSettings = ManagementMeetingSettings::forTenant();
        $meetingCutoff = Carbon::parse($today->toDateString().' '.($meetingSettings->meeting_time ?: ManagementMeetingSettings::DEFAULT_MEETING_TIME));
        $meetingDueToday = $now->gte($meetingCutoff);

        $items = WorkItem::withoutGlobalScope(ParentOnly::class)
            ->whereNotNull('due_at')
            ->whereNotNull('employee_id')
            ->where(function ($query) use ($today, $meetingDueToday) {
                $query->whereDate('due_at', '<', $today->toDateString());
                if ($meetingDueToday) {
                    $query->orWhere(function ($q) use ($today) {
                        $q->where('source', 'management_meeting')->whereDate('due_at', $today->toDateString());
                    });
                }
            })
            ->whereNotIn('status', ['done'])
            ->where('type', '!=', 'event')
            ->whereNull('cancelled_at')
            ->when($employeeIds !== null, fn ($q) => $q->whereIn('employee_id', $employeeIds))
            ->with(['employee', 'projectRef'])
            ->orderBy('due_at')
            ->get();

        $groups = [];

        foreach ($items as $item) {
            if (! $item->employee) {
                continue;
            }

            $ownerId = $item->employee_id;
            $groups[$ownerId] ??= ['owner_id' => $ownerId, 'owner_name' => $item->employee->display_name, 'cards' => []];
            $groups[$ownerId]['cards'][] = [
                'id' => $item->id,
                'title' => $item->title,
                'days_overdue' => (int) $today->diffInDays($item->due_at->copy()->startOfDay(), true),
                'owner_reports_to_id' => $item->employee->reports_to_id,
                'pm_id' => $item->projectRef?->pm_id,
            ];
        }

        return array_values($groups);
    }

    /**
     * Reassign is deliberately narrower than nudge: management tier (management,
     * director; HR is excluded even though it may nudge), the owner's direct
     * reports_to_id manager, or the card's project PM. One rule for the POST gate and
     * the button (QA F2: no Reassign button the viewer cannot use).
     */
    public function canReassign(Employee $actor, string $effectiveRole, ?int $ownerReportsToId, ?int $pmId): bool
    {
        if (in_array($effectiveRole, Permissions::MANAGEMENT_TIER, true)) {
            return true;
        }

        return ($ownerReportsToId !== null && $ownerReportsToId === $actor->id)
            || ($pmId !== null && $pmId === $actor->id);
    }

    /**
     * Stamp `can_reassign` on every card of an overdue() result for this viewer.
     *
     * @param  list<array{owner_id:int,owner_name:string,cards:list<array<string,mixed>>}>  $overdue
     * @return list<array{owner_id:int,owner_name:string,cards:list<array<string,mixed>>}>
     */
    public function withReassignFlags(array $overdue, Employee $actor, string $effectiveRole): array
    {
        foreach ($overdue as &$group) {
            foreach ($group['cards'] as &$card) {
                $card['can_reassign'] = $this->canReassign($actor, $effectiveRole, $card['owner_reports_to_id'] ?? null, $card['pm_id'] ?? null);
            }
            unset($card);
        }
        unset($group);

        return $overdue;
    }
}
