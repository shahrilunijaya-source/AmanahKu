<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * One-time conversion of salary_structures.allowances (JSON [{name, amount}]) into
     * open-ended Fixed Transactions against the "Fixed Allowance" catalogue item, so run
     * generation can stop reading that JSON column (see PayrollController).
     *
     * Deliberately NOT retroactive: every migrated Fixed Transaction starts this month,
     * not the structure's effective_from — back-dating would change what an already-run
     * past period would regenerate if it were ever recomputed. The typed allowance name
     * is kept as the transaction's remarks so nothing entered is lost.
     *
     * The allowances column itself is left in place, unread from here on — dropping it is
     * a later, separate decision (a finalized payslip's history and any rollback of this
     * migration both still want it there).
     */
    public function up(): void
    {
        $now = now();
        $currentPeriod = $now->format('Y-m');

        $itemIdByTenant = DB::table('payroll_items')
            ->where('code', 'fixed-allowance')
            ->pluck('id', 'tenant_id');

        $rows = DB::table('salary_structures')->whereNotNull('allowances')->get(['id', 'tenant_id', 'employee_id', 'allowances']);

        foreach ($rows as $row) {
            $itemId = $itemIdByTenant->get($row->tenant_id);
            if ($itemId === null) {
                // No catalogue item seeded for this tenant yet — shouldn't happen (every
                // tenant is seeded, see 2026_08_25_190000), but skip rather than crash a
                // release migration over one tenant's stale data.
                continue;
            }

            $allowances = json_decode((string) $row->allowances, true) ?: [];
            foreach ($allowances as $allowance) {
                $amount = round((float) ($allowance['amount'] ?? 0), 2);
                $name = trim((string) ($allowance['name'] ?? ''));
                if ($amount <= 0) {
                    continue;
                }

                DB::table('fixed_transactions')->insert([
                    'tenant_id' => $row->tenant_id,
                    'employee_id' => $row->employee_id,
                    'payroll_item_id' => $itemId,
                    'amount' => $amount,
                    'start_period' => $currentPeriod,
                    'end_period' => null,
                    'last_amount' => null,
                    'prorate' => false,
                    'remarks' => $name !== '' ? $name : null,
                    'created_by_id' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Deliberately empty: by the time this rolls back, HR may have added, edited or
        // ended real Fixed Transactions of their own against "Fixed Allowance" — there is
        // no way to tell those apart from the ones this migration inserted, so a rollback
        // must not delete data that isn't reliably this migration's to take back.
    }
};
