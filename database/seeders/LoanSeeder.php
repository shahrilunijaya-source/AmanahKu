<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\LoanRequest;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

class LoanSeeder extends Seeder
{
    /**
     * Seed ~3 loan/advance requests across statuses for the first tenant's
     * employees. Safe to re-run: skips entirely if the first tenant already has
     * requests, and guards against tenants with no employees.
     */
    public function run(): void
    {
        $tenant = Tenant::query()->orderBy('id')->first();
        if (! $tenant) {
            return;
        }

        $tid = $tenant->id;

        // Idempotent-ish: global scope is inactive in seeders, so scope to the tenant.
        if (LoanRequest::where('tenant_id', $tid)->exists()) {
            return;
        }

        $employees = Employee::where('tenant_id', $tid)->orderBy('id')->take(3)->get();
        if ($employees->isEmpty()) {
            return;
        }

        // [employee index, type, amount, reason, installments, status]
        $plan = [
            [0, 'loan', 5000.00, 'Home renovation — repay over 12 months', 12, 'submitted'],
            [1, 'advance', 1500.00, 'Cash-flow gap before payday', 1, 'approved'],
            [2, 'loan', 8000.00, 'Vehicle down payment', 24, 'rejected'],
        ];

        foreach ($plan as $row) {
            $employee = $employees->get($row[0]);
            if (! $employee) {
                continue;
            }

            LoanRequest::create([
                'tenant_id' => $tid,
                'employee_id' => $employee->id,
                'type' => $row[1],
                'amount' => $row[2],
                'reason' => $row[3],
                'installments' => $row[4],
                'status' => $row[5],
                'approved_by_employee_id' => $row[5] === 'submitted' ? null : $employee->id,
                'decided_at' => $row[5] === 'submitted' ? null : now(),
            ]);
        }
    }
}
