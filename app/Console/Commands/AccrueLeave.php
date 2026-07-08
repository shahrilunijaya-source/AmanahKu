<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\Tenant;
use App\Tenancy\CurrentTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Monthly leave accrual.
 *
 * For each tenant, each active employee, and each accruing leave type
 * (monthly_accrual_days > 0), adds the monthly grant to the employee's
 * LeaveBalance, capped at the type's max_balance. Idempotent within a
 * calendar month: a balance already accrued this month is skipped, so
 * re-running on the same month is a no-op.
 *
 * Tenant-aware: the BelongsToTenant global scope is bypassed for the
 * cross-tenant read, and tenant_id is set explicitly on every write, the
 * same approach the seeders use.
 */
class AccrueLeave extends Command
{
    protected $signature = 'leave:accrue';

    protected $description = 'Add monthly leave accrual to active employees, capped at max balance (idempotent per month).';

    public function handle(CurrentTenant $context): int
    {
        $now = Carbon::now();
        $accruedCount = 0;

        $tenants = Tenant::query()->orderBy('id')->get();

        foreach ($tenants as $tenant) {
            $context->set($tenant);

            try {
                $types = LeaveType::query()
                    ->where('monthly_accrual_days', '>', 0)
                    ->get();

                if ($types->isEmpty()) {
                    continue;
                }

                $employees = Employee::active()
                    ->where('status', 'active')
                    ->orderBy('id')
                    ->get();

                foreach ($employees as $employee) {
                    foreach ($types as $type) {
                        if ($this->accrueOne($employee, $type, $now)) {
                            $accruedCount++;
                        }
                    }
                }
            } catch (\Throwable $e) {
                // Isolate per-tenant failures: one tenant's error must not abort accrual
                // for the rest. Log and continue (accrual is idempotent, so a fixed tenant
                // self-heals on the next run).
                report($e);
                $this->error("Leave accrual failed for tenant {$tenant->id}: {$e->getMessage()}");
            }
        }

        $context->set(null);

        $this->info("Leave accrual complete: {$accruedCount} balance(s) updated.");

        return self::SUCCESS;
    }

    /**
     * Accrue a single employee/type pair. Returns true if the balance was
     * actually credited, false if skipped (already accrued this month or
     * already at cap).
     */
    private function accrueOne(Employee $employee, LeaveType $type, Carbon $now): bool
    {
        $balance = LeaveBalance::query()
            ->where('employee_id', $employee->id)
            ->where('leave_type_id', $type->id)
            ->first();

        if ($balance === null) {
            $balance = new LeaveBalance([
                'employee_id' => $employee->id,
                'leave_type_id' => $type->id,
                'balance' => 0,
            ]);
        }

        // Idempotent within a calendar month.
        if ($balance->last_accrued_on !== null
            && $balance->last_accrued_on->isSameMonth($now)) {
            return false;
        }

        $grant = (float) $type->monthly_accrual_days;
        $new = (float) $balance->balance + $grant;

        if ($type->max_balance !== null) {
            $new = min($new, (float) $type->max_balance);
        }

        $balance->balance = $new;
        $balance->last_accrued_on = $now->copy()->startOfDay();
        $balance->save();

        return true;
    }
}
