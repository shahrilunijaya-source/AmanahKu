<?php

namespace Database\Seeders;

use App\Models\ClearanceItem;
use App\Models\Employee;
use App\Models\OffboardingCase;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

class OffboardingSeeder extends Seeder
{
    /**
     * Seed 1 in-progress offboarding case + its clearance checklist for an employee
     * of the first tenant. Safe to re-run: skips if the first tenant already has a
     * case, and guards against tenants with no employees. The global tenant scope is
     * inactive in seeders, so tenant_id is set explicitly.
     */
    public function run(): void
    {
        $tenant = Tenant::query()->orderBy('id')->first();
        if (! $tenant) {
            return;
        }

        $tid = $tenant->id;

        if (OffboardingCase::where('tenant_id', $tid)->exists()) {
            return;
        }

        $employee = Employee::where('tenant_id', $tid)->orderByDesc('id')->first();
        if (! $employee) {
            return;
        }

        $case = OffboardingCase::create([
            'tenant_id' => $tid,
            'employee_id' => $employee->id,
            'last_day' => now()->addDays(12)->toDateString(),
            'reason' => 'resignation',
            'status' => 'in_progress',
            'notes' => 'Accepted an external offer. Two-week handover in progress.',
        ]);

        // [department, title, done]
        $items = [
            ['IT', 'Revoke system & email access', false],
            ['IT', 'Collect laptop & devices', false],
            ['HR', 'Conduct exit interview', true],
            ['HR', 'Process final documentation', false],
            ['Finance', 'Settle final salary & claims', false],
            ['Finance', 'Recover company advances', true],
            ['Manager', 'Knowledge handover sign-off', false],
            ['Admin', 'Collect access card & keys', false],
        ];

        foreach ($items as $i => [$department, $title, $done]) {
            ClearanceItem::create([
                'offboarding_case_id' => $case->id,
                'department' => $department,
                'title' => $title,
                'done' => $done,
                'sort' => $i,
            ]);
        }
    }
}
