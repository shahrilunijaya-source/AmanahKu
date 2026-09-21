<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\PayrollSubmission;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\PayrollDeadlineDigest as PayrollDeadlineDigestNotification;
use App\Tenancy\CurrentTenant;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

/**
 * Spec F12: reminds each tenant's HR/management users about statutory filings that are
 * still not submitted, five days and one day before the deadline — the 10th and the 14th
 * for the monthly 15th, and 20 February / 20 March for Form EA and Form E.
 *
 * Nothing is sent on any other day, and nothing is sent when every filing due is already
 * recorded as submitted.
 */
class PayrollDeadlineDigest extends Command
{
    protected $signature = 'payroll:deadline-digest';

    protected $description = "Email each tenant's HR/management users the statutory filings still due.";

    /** Roles that receive the digest. */
    private const RECIPIENT_ROLES = ['management', 'hr'];

    public function handle(CurrentTenant $context): int
    {
        $today = Carbon::now();
        $dueOn = $this->deadlineFor($today);
        if ($dueOn === null) {
            $this->info('Not a reminder day; nothing sent.');

            return self::SUCCESS;
        }

        $sent = 0;
        foreach (Tenant::query()->orderBy('id')->get() as $tenant) {
            $context->set($tenant);

            try {
                $rows = PayrollSubmission::with('payrollRun')
                    ->whereDate('due_on', $dueOn->toDateString())
                    ->whereNull('submitted_at')
                    ->orderBy('agency')->get();
                $recipients = $this->recipientsFor($tenant);

                if ($rows->isEmpty() || $recipients->isEmpty()) {
                    continue;
                }

                Notification::send($recipients, new PayrollDeadlineDigestNotification($tenant, $rows));
                $sent++;
            } catch (\Throwable $e) {
                // Isolate per-tenant failures so one bad tenant does not silence the rest.
                report($e);
                $this->error("Payroll deadline digest failed for tenant {$tenant->id}: {$e->getMessage()}");
            }
        }

        $context->set(null);
        $this->info("Payroll deadline digest sent for {$sent} tenant(s).");

        return self::SUCCESS;
    }

    /** The deadline today's run is reminding about, or null on a day that sends nothing. */
    private function deadlineFor(Carbon $today): ?Carbon
    {
        if (in_array($today->day, [10, 14], true)) {
            return $today->copy()->day(15)->startOfDay();
        }
        // Form EA is due at the end of February and Form E on 31 March: remind on the 20th.
        if ($today->day === 20 && $today->month === 2) {
            return $today->copy()->endOfMonth()->startOfDay();
        }
        if ($today->day === 20 && $today->month === 3) {
            return $today->copy()->day(31)->startOfDay();
        }

        return null;
    }

    /** @return Collection<int, User> */
    private function recipientsFor(Tenant $tenant): Collection
    {
        return User::query()
            ->whereIn('id', $tenant->users()->wherePivotIn('role', self::RECIPIENT_ROLES)->pluck('users.id'))
            ->get();
    }
}
