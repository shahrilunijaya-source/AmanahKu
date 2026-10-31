<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Claim;
use App\Models\LeaveRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\VerifyDigest as VerifyDigestNotification;
use App\Tenancy\CurrentTenant;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Daily email to each manager listing the leave and claims still waiting for their
 * verification: one email per manager, not one per request. The in-app bell still
 * fires per request at submit time; this is the email copy HR asked for, grouped.
 *
 * Lists everything still at `submitted`, not only what is new since yesterday, so an
 * item a manager has not acted on shows up again the next morning as a reminder.
 * Recipients come from each requester's verifiers() (primary superior plus dotted-line
 * managers, archived ones dropped), the same set allowed to verify.
 */
class VerifyDigest extends Command
{
    protected $signature = 'approvals:verify-digest';

    protected $description = 'Email each manager one list of the leave and claims awaiting their verification.';

    public function handle(CurrentTenant $context): int
    {
        $sent = 0;

        foreach (Tenant::query()->orderBy('id')->get() as $tenant) {
            $context->set($tenant);

            try {
                $sent += $this->sendFor();
            } catch (\Throwable $e) {
                report($e);
                $this->error("Verify digest failed for tenant {$tenant->id}: {$e->getMessage()}");
            }
        }

        $context->set(null);

        $this->info("Verify digest sent to {$sent} manager(s).");

        return self::SUCCESS;
    }

    /** Send the current tenant's digests; returns how many managers were emailed. */
    private function sendFor(): int
    {
        /** @var array<int, list<array{kind: string, who: string, what: string, since: Carbon}>> $byManager */
        $byManager = [];

        $leaves = LeaveRequest::query()->where('status', 'submitted')
            ->whereHas('employee', fn (Builder $e) => $e->whereNull('archived_at'))
            ->with(['leaveType', 'employee.reportsTo', 'employee.additionalManagers'])
            ->orderBy('created_at')->get();

        foreach ($leaves as $leave) {
            $range = $leave->date_from->format('j M')
                .($leave->date_to->isSameDay($leave->date_from) ? '' : ' to '.$leave->date_to->format('j M'));
            $line = [
                'kind' => 'Leave',
                'who' => $leave->employee->name,
                'what' => $leave->leaveType->name.' · '.$range.' ('.rtrim(rtrim(number_format($leave->days, 1), '0'), '.').'d)',
                'since' => $leave->created_at,
            ];
            foreach ($leave->employee->verifiers() as $manager) {
                if ($manager->user_id) {
                    $byManager[$manager->user_id][] = $line;
                }
            }
        }

        $claims = Claim::query()->where('status', 'submitted')
            ->whereHas('employee', fn (Builder $e) => $e->whereNull('archived_at'))
            ->with(['employee.reportsTo', 'employee.additionalManagers'])
            ->orderBy('created_at')->get();

        foreach ($claims as $claim) {
            $line = [
                'kind' => 'Claim',
                'who' => $claim->employee->name,
                'what' => $claim->title.' · RM '.number_format($claim->amount, 2),
                'since' => $claim->created_at,
            ];
            foreach ($claim->employee->verifiers() as $manager) {
                if ($manager->user_id) {
                    $byManager[$manager->user_id][] = $line;
                }
            }
        }

        foreach ($byManager as $userId => $lines) {
            User::find($userId)?->notify(new VerifyDigestNotification($lines));
        }

        return count($byManager);
    }
}
