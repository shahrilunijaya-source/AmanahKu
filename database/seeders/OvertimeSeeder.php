<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\OvertimeRequest;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

class OvertimeSeeder extends Seeder
{
    /**
     * Seed a handful of overtime requests across statuses for Unijaya's employees.
     * Safe to re-run: skips entirely if the tenant already has requests, and guards
     * against a missing tenant or tenants with no employees. The global tenant scope
     * is inactive in seeders, so tenant_id is set explicitly on every row.
     */
    public function run(): void
    {
        $tenant = Tenant::where('slug', 'unijaya')->first();
        if (! $tenant) {
            return;
        }

        $tid = $tenant->id;

        if (OvertimeRequest::where('tenant_id', $tid)->exists()) {
            return;
        }

        $employees = Employee::where('tenant_id', $tid)->orderBy('id')->take(4)->get();
        if ($employees->isEmpty()) {
            return;
        }

        // [employee index, ot_date, hours, rate_multiplier, reason, status]
        $plan = [
            [0, '-3 days', 4.00, 1.50, 'Month-end closing — finance backlog', 'submitted'],
            [1, '-6 days', 3.50, 2.00, 'Weekend deployment support', 'approved'],
            [2, '-8 days', 2.00, 1.50, 'Client demo preparation after hours', 'rejected'],
            [3, '-1 day', 5.00, 1.50, 'Server migration overnight window', 'submitted'],
        ];

        foreach ($plan as $row) {
            $employee = $employees->get($row[0]);
            if (! $employee) {
                continue;
            }

            OvertimeRequest::create([
                'tenant_id' => $tid,
                'employee_id' => $employee->id,
                'ot_date' => now()->modify($row[1])->toDateString(),
                'hours' => $row[2],
                'rate_multiplier' => $row[3],
                'reason' => $row[4],
                'status' => $row[5],
                'decided_by_id' => $row[5] === 'submitted' ? null : $employee->id,
                'decided_at' => $row[5] === 'submitted' ? null : now(),
            ]);
        }
    }
}
