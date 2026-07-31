<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\PettyCashFloat;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

class PettyCashSeeder extends Seeder
{
    /**
     * Seed 1-2 branch petty cash floats (RM 2000 opening) for the Unijaya tenant,
     * each with a few disbursements and one replenishment so the balance sits
     * mid-way and the log carries signal. Safe to re-run: skips if that tenant
     * already has a float, and guards against a missing tenant / no branches.
     * No tenant session exists in seeders, so tenant_id is set explicitly.
     */
    public function run(): void
    {
        $tenant = Tenant::where('slug', 'unijaya')->first();
        if (! $tenant) {
            return;
        }

        $tid = $tenant->id;

        // Global scope is inactive in seeders, so scope to the tenant explicitly.
        if (PettyCashFloat::where('tenant_id', $tid)->exists()) {
            return;
        }

        $branches = Branch::where('tenant_id', $tid)->orderBy('id')->take(2)->get();
        if ($branches->isEmpty()) {
            return;
        }

        $custodian = Employee::where('tenant_id', $tid)->orderBy('id')->first();

        // [branch index, name, opening, [ [type, amount, payee, purpose|note, date], ... ] ]
        $plan = [
            [0, 'PJ HQ Petty Cash', 2000.00, [
                ['disbursement', 45.00, 'Speedmart 99', 'Pantry supplies', '2026-06-05'],
                ['disbursement', 120.00, 'Grab', 'Courier — client documents', '2026-06-09'],
                ['disbursement', 88.50, 'Mr DIY', 'Office stationery', '2026-06-12'],
                ['replenishment', 250.00, null, 'Top-up from finance', '2026-06-16'],
                ['disbursement', 60.00, 'Pos Laju', 'Parcel postage', '2026-06-20'],
            ]],
            [1, 'Seremban 2 Petty Cash', 2000.00, [
                ['disbursement', 150.00, 'Petronas', 'Fuel — site visit', '2026-06-07'],
                ['disbursement', 35.00, 'Restoran Sri Murni', 'Staff refreshments', '2026-06-13'],
                ['replenishment', 200.00, null, 'Top-up from finance', '2026-06-18'],
            ]],
        ];

        foreach ($plan as $row) {
            $branch = $branches->get($row[0]);
            if (! $branch) {
                continue;
            }

            $float = PettyCashFloat::create([
                'tenant_id' => $tid,
                'branch_id' => $branch->id,
                'name' => $row[1],
                'opening_balance' => $row[2],
                'balance' => $row[2],
                'custodian_employee_id' => $custodian?->id,
                'is_active' => true,
            ]);

            foreach ($row[3] as $t) {
                $float->txns()->create([
                    'tenant_id' => $tid,
                    'type' => $t[0],
                    'amount' => $t[1],
                    'payee' => $t[0] === 'disbursement' ? $t[2] : null,
                    'purpose' => $t[0] === 'disbursement' ? $t[3] : null,
                    'note' => $t[0] === 'replenishment' ? $t[3] : null,
                    'recorded_by_id' => $custodian?->id,
                    'txn_date' => $t[4],
                ]);
            }

            // Derive the running balance from the seeded txns (global scope inactive — scope by tenant).
            $disbursed = (float) $float->txns()->where('tenant_id', $tid)->where('type', 'disbursement')->sum('amount');
            $replenished = (float) $float->txns()->where('tenant_id', $tid)->where('type', 'replenishment')->sum('amount');
            $float->update(['balance' => (float) $row[2] + $replenished - $disbursed]);
        }
    }
}
