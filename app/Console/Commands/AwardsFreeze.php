<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Support\Awards;
use App\Tenancy\CurrentTenant;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * CR-14a / Global Clause "Frozen award data": every day at 23:59, on the last calendar
 * day of the month only, take a snapshot of that month's award figures from approved
 * data as of this moment. `award_snapshots` gains one row per (employee, award_key) the
 * person is eligible for. Idempotent: a tenant+month that already has snapshot rows is
 * skipped, so a change made after the freeze (an archive, a later approval) never alters
 * it — it is simply never read again for this month.
 *
 * ponytail: idempotency is keyed on "any snapshot row exists for this tenant+month". A
 * tenant with literally zero eligible people for every one of the 15 awards in a given
 * month would re-run every night after that — no acceptance test reaches this corner, and
 * a real tenant always has at least a `billable` or `done_and_dusted` row.
 */
class AwardsFreeze extends Command
{
    protected $signature = 'awards:freeze';

    protected $description = 'Freeze this month\'s award snapshot at 23:59 on the last calendar day of the month.';

    public function handle(CurrentTenant $context, Awards $awards): int
    {
        $today = Carbon::now();
        $written = 0;

        foreach (Tenant::query()->orderBy('id')->get() as $tenant) {
            $context->set($tenant);

            try {
                $written += $this->freezeTenant($today, $awards);
            } catch (\Throwable $e) {
                report($e);
                $this->error("Awards freeze failed for tenant {$tenant->id}: {$e->getMessage()}");
            }
        }

        $context->set(null);
        $this->info("Award snapshot rows written: {$written}.");

        return self::SUCCESS;
    }

    private function freezeTenant(Carbon $today, Awards $awards): int
    {
        if (! $today->isSameDay($today->copy()->endOfMonth())) {
            return 0;
        }

        $tenantId = app(CurrentTenant::class)->id();
        $month = $today->copy()->startOfMonth();

        $alreadyFrozen = DB::table('award_snapshots')
            ->where('tenant_id', $tenantId)->whereDate('month', $month->toDateString())->exists();
        if ($alreadyFrozen) {
            return 0;
        }

        $frozenAt = Carbon::now();
        $rows = [];
        foreach ($awards->compute($month) as $key => $entries) {
            foreach ($entries as $employeeId => $data) {
                $rows[] = [
                    'tenant_id' => $tenantId,
                    'month' => $month->toDateString(),
                    'award_key' => $key,
                    'employee_id' => $employeeId,
                    'value' => $data['value'],
                    'label' => $data['label'],
                    'frozen_at' => $frozenAt,
                    'created_at' => $frozenAt,
                    'updated_at' => $frozenAt,
                ];
            }
        }

        if ($rows !== []) {
            DB::table('award_snapshots')->insert($rows);
        }

        return count($rows);
    }
}
