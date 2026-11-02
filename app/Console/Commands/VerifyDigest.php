<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Claim;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\VerifyDigest as VerifyDigestNotification;
use App\Support\Permissions;
use App\Tenancy\CurrentTenant;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Daily email listing the leave and claims waiting on each person, one email per person
 * instead of one per request. The in-app bell still fires per request; this is the email
 * copy HR asked for, grouped.
 *
 * Two lists, both in the same email when someone has both:
 * - to verify: `submitted` requests, sent to each requester's verifiers() (primary
 *   superior plus dotted-line managers, archived ones dropped).
 * - to approve: `verified` requests, sent to every final approver (HR, management,
 *   director) except the requester, the verifier and whoever filed it, matching the
 *   rules in assertApprover().
 *
 * Everything still pending is listed, not only what is new since yesterday, so an item
 * nobody has acted on shows up again the next morning as a reminder.
 */
class VerifyDigest extends Command
{
    protected $signature = 'approvals:verify-digest';

    protected $description = 'Email each manager and final approver one list of the leave and claims waiting on them.';

    public function handle(CurrentTenant $context): int
    {
        $sent = 0;

        foreach (Tenant::query()->orderBy('id')->get() as $tenant) {
            $context->set($tenant);

            try {
                $sent += $this->sendFor($tenant);
            } catch (\Throwable $e) {
                report($e);
                $this->error("Approval digest failed for tenant {$tenant->id}: {$e->getMessage()}");
            }
        }

        $context->set(null);

        $this->info("Approval digest sent to {$sent} person(s).");

        return self::SUCCESS;
    }

    /** Send the current tenant's digests; returns how many people were emailed. */
    private function sendFor(Tenant $tenant): int
    {
        /** @var array<int, array{verify: list<array{kind: string, who: string, what: string, since: Carbon}>, approve: list<array{kind: string, who: string, what: string, since: Carbon}>}> $byUser */
        $byUser = [];

        // Final approvers: user id => their employee id (null when they have no employee record).
        $approverUserIds = $tenant->users()->wherePivotIn('role', Permissions::FINAL_APPROVAL_ROLES)->pluck('users.id')->all();
        $approverEmployeeIds = Employee::query()->whereIn('user_id', $approverUserIds)->pluck('id', 'user_id')->all();
        $archivedApprovers = Employee::query()->archived()->whereIn('user_id', $approverUserIds)->pluck('user_id')->all();
        $approverUserIds = array_diff($approverUserIds, $archivedApprovers);

        $route = function (LeaveRequest|Claim $record, array $line) use (&$byUser, $approverUserIds, $approverEmployeeIds): void {
            if ($record->status === 'submitted') {
                foreach ($record->employee->verifiers() as $manager) {
                    if ($manager->user_id) {
                        $byUser[$manager->user_id]['verify'][] = $line;
                    }
                }

                return;
            }

            $excluded = array_filter([$record->employee_id, $record->verified_by_id, $record->filed_by_id]);
            foreach ($approverUserIds as $userId) {
                if (! in_array($approverEmployeeIds[$userId] ?? null, $excluded, true)) {
                    $byUser[$userId]['approve'][] = $line;
                }
            }
        };

        $leaves = LeaveRequest::query()->whereIn('status', ['submitted', 'verified'])
            ->whereHas('employee', fn (Builder $e) => $e->whereNull('archived_at'))
            ->with(['leaveType', 'employee.reportsTo', 'employee.additionalManagers'])
            ->orderBy('created_at')->get();

        foreach ($leaves as $leave) {
            $range = $leave->date_from->format('j M')
                .($leave->date_to->isSameDay($leave->date_from) ? '' : ' to '.$leave->date_to->format('j M'));
            $route($leave, [
                'kind' => 'Leave',
                'who' => $leave->employee->name,
                'what' => $leave->leaveType->name.' · '.$range.' ('.rtrim(rtrim(number_format($leave->days, 1), '0'), '.').'d)',
                'since' => $leave->created_at,
            ]);
        }

        $claims = Claim::query()->whereIn('status', ['submitted', 'verified'])
            ->whereHas('employee', fn (Builder $e) => $e->whereNull('archived_at'))
            ->with(['employee.reportsTo', 'employee.additionalManagers'])
            ->orderBy('created_at')->get();

        foreach ($claims as $claim) {
            $route($claim, [
                'kind' => 'Claim',
                'who' => $claim->employee->name,
                'what' => $claim->title.' · RM '.number_format($claim->amount, 2),
                'since' => $claim->created_at,
            ]);
        }

        foreach ($byUser as $userId => $lists) {
            User::find($userId)?->notify(new VerifyDigestNotification($lists['verify'] ?? [], $lists['approve'] ?? []));
        }

        return count($byUser);
    }
}
