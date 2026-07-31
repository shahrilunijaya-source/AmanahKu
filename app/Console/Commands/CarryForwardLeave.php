<?php

namespace App\Console\Commands;

use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\Tenant;
use App\Tenancy\CurrentTenant;
use Illuminate\Console\Command;

/**
 * Year-boundary leave carry-forward.
 *
 * For every accruing leave type, each employee's unused balance is carried
 * into the new year up to the type's max_carry_forward; anything above that
 * cap expires. A null max_carry_forward means no carry-over — the full
 * balance expires. Accrual tracking (last_accrued_on) is reset so the new
 * year accrues fresh from the first monthly run.
 *
 * Tenant-aware: bypasses the BelongsToTenant global scope for the
 * cross-tenant read and sets tenant_id via the active-tenant context, the
 * same approach the seeders use.
 */
class CarryForwardLeave extends Command
{
    protected $signature = 'leave:carry-forward';

    protected $description = 'Carry unused leave up to the cap, expire the remainder, and reset accrual tracking (run at year start).';

    public function handle(CurrentTenant $context): int
    {
        $expiredTotal = 0.0;
        $balancesProcessed = 0;

        $tenants = Tenant::query()->orderBy('id')->get();

        foreach ($tenants as $tenant) {
            $context->set($tenant);

            try {
                $types = LeaveType::query()
                    ->where('monthly_accrual_days', '>', 0)
                    ->get()
                    ->keyBy('id');

                if ($types->isEmpty()) {
                    continue;
                }

                $balances = LeaveBalance::query()
                    ->whereIn('leave_type_id', $types->keys())
                    ->orderBy('id')
                    ->get();

                foreach ($balances as $balance) {
                    $type = $types->get($balance->leave_type_id);
                    $cap = $type->max_carry_forward !== null ? (float) $type->max_carry_forward : 0.0;

                    $current = (float) $balance->balance;
                    $carried = min($current, $cap);
                    $expiredTotal += max(0.0, $current - $carried);

                    $balance->balance = $carried;
                    $balance->last_accrued_on = null;
                    $balance->save();

                    $balancesProcessed++;
                }
            } catch (\Throwable $e) {
                // Isolate per-tenant failures so one tenant's error can't abort the
                // year-boundary run for the rest.
                report($e);
                $this->error("Carry-forward failed for tenant {$tenant->id}: {$e->getMessage()}");
            }
        }

        $context->set(null);

        $this->info("Carry-forward complete: {$balancesProcessed} balance(s) processed, ".rtrim(rtrim(number_format($expiredTotal, 2), '0'), '.').' day(s) expired.');

        return self::SUCCESS;
    }
}
