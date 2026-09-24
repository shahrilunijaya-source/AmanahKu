<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Payslip;
use App\Models\Tenant;
use App\Notifications\PayslipPublished;
use App\Tenancy\CurrentTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Runs on the 5th: emails every staff member with a login that last month's payslip is
 * ready to download. Only payslips in a published run are mentioned, so a run HR has
 * not released yet stays quiet until they publish it (which sends its own email).
 */
class PayslipReadyReminder extends Command
{
    protected $signature = 'payroll:payslip-ready';

    protected $description = "Email staff that last month's payslip is ready to download.";

    public function handle(CurrentTenant $context): int
    {
        $period = Carbon::now()->subMonthNoOverflow()->format('Y-m');

        $sent = 0;
        foreach (Tenant::query()->orderBy('id')->get() as $tenant) {
            $context->set($tenant);

            try {
                $payslips = Payslip::with(['payrollRun', 'employee.user'])
                    ->whereHas('payrollRun', fn ($q) => $q->where('period', $period)->whereNotNull('published_at'))
                    ->get();

                foreach ($payslips as $payslip) {
                    $user = $payslip->employee?->user;
                    if ($user !== null) {
                        $user->notify(new PayslipPublished($payslip));
                        $sent++;
                    }
                }
            } catch (\Throwable $e) {
                // Isolate per-tenant failures so one bad tenant does not silence the rest.
                report($e);
                $this->error("Payslip ready email failed for tenant {$tenant->id}: {$e->getMessage()}");
            }
        }

        $context->set(null);
        $this->info("Payslip ready email sent for {$sent} payslip(s) of {$period}.");

        return self::SUCCESS;
    }
}
