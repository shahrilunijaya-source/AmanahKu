<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\ExitInterview;
use App\Models\Resignation;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

class ResignationSeeder extends Seeder
{
    /**
     * Seed two resignations for Unijaya: one 'submitted' (awaiting acknowledgement)
     * and one 'acknowledged' with a completed exit interview (so the privileged
     * view shows confidential interview content). Safe to re-run: skips if Unijaya
     * already has a resignation, and guards against a missing tenant/employees.
     * The global tenant scope is inactive in seeders, so tenant_id is set explicitly.
     * Aisyah Rahman (the demo HR actor) is never used as a resigning employee.
     */
    public function run(): void
    {
        $tenant = Tenant::where('slug', 'unijaya')->first();
        if (! $tenant) {
            return;
        }

        $tid = $tenant->id;

        if (Resignation::where('tenant_id', $tid)->exists()) {
            return;
        }

        $employees = Employee::where('tenant_id', $tid)
            ->where('name', '!=', 'Aisyah Rahman')
            ->orderBy('id')
            ->take(2)
            ->get();
        if ($employees->count() < 2) {
            return;
        }

        // Resignation 1 — submitted, pending acknowledgement.
        Resignation::create([
            'tenant_id' => $tid,
            'employee_id' => $employees[0]->id,
            'submitted_at' => now()->subDays(2),
            'last_working_date' => now()->addDays(30)->toDateString(),
            'notice_days' => 30,
            'reason' => 'Accepted a senior role at another firm. Grateful for the time here.',
            'status' => 'submitted',
        ]);

        // Resignation 2 — acknowledged, with a completed confidential exit interview.
        $acknowledged = Resignation::create([
            'tenant_id' => $tid,
            'employee_id' => $employees[1]->id,
            'submitted_at' => now()->subDays(20),
            'last_working_date' => now()->addDays(10)->toDateString(),
            'notice_days' => 30,
            'reason' => 'Relocating to another state for family reasons.',
            'status' => 'acknowledged',
            'acknowledged_at' => now()->subDays(18),
            'acknowledged_by_id' => $employees[0]->id,
        ]);

        ExitInterview::create([
            'tenant_id' => $tid,
            'resignation_id' => $acknowledged->id,
            'reason_category' => 'Relocation',
            'would_recommend' => true,
            'ratings' => ['management' => 4, 'culture' => 5, 'growth' => 3, 'compensation' => 3],
            'feedback' => 'Strong team culture and supportive management. Career growth path could be clearer.',
            'conducted_by_id' => $employees[0]->id,
            'conducted_at' => now()->subDays(16),
        ]);
    }
}
