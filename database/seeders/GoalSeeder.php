<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\Goal;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

class GoalSeeder extends Seeder
{
    /**
     * Seed ~2 goals (each with key results) for the first tenant's primary
     * employee (Aisyah). Guarded: skips when there is no tenant/employee or when
     * goals already exist for the tenant. No tenant session exists during seeding,
     * so tenant_id is set explicitly.
     */
    public function run(): void
    {
        $tenant = Tenant::orderBy('id')->first();
        if (! $tenant) {
            return;
        }

        if (Goal::where('tenant_id', $tenant->id)->exists()) {
            return;
        }

        $owner = Employee::where('tenant_id', $tenant->id)->where('name', 'like', 'Aisyah%')->first()
            ?? Employee::where('tenant_id', $tenant->id)->orderBy('id')->first();
        if (! $owner) {
            return;
        }

        // 1) Delivery objective with mixed progress.
        $delivery = Goal::create([
            'tenant_id' => $tenant->id,
            'employee_id' => $owner->id,
            'title' => 'Ship the payroll module to production',
            'description' => 'Take the payroll run feature from beta to a stable, audited release.',
            'category' => 'delivery',
            'period' => '2026 H1',
            'status' => 'active',
        ]);

        foreach ([
            ['Close all P1 payroll bugs', 80, '0 open P1'],
            ['Complete statutory calculation audit', 60, 'Signed off by Finance'],
            ['Onboard 3 pilot tenants', 33, '3 tenants live'],
        ] as [$title, $progress, $target]) {
            $delivery->keyResults()->create([
                'tenant_id' => $tenant->id,
                'employee_id' => $owner->id,
                'title' => $title,
                'progress' => $progress,
                'target_label' => $target,
            ]);
        }

        // 2) Growth objective.
        $growth = Goal::create([
            'tenant_id' => $tenant->id,
            'employee_id' => $owner->id,
            'title' => 'Grow as a technical lead',
            'description' => 'Build mentoring and architecture-review habits across the half.',
            'category' => 'growth',
            'period' => '2026 H1',
            'status' => 'active',
        ]);

        foreach ([
            ['Run weekly mentoring sessions', 50, '12 sessions'],
            ['Lead 2 architecture reviews', 50, '2 reviews'],
        ] as [$title, $progress, $target]) {
            $growth->keyResults()->create([
                'tenant_id' => $tenant->id,
                'employee_id' => $owner->id,
                'title' => $title,
                'progress' => $progress,
                'target_label' => $target,
            ]);
        }
    }
}
