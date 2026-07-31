<?php

namespace Database\Seeders;

use App\Models\Project;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

class ProjectSeeder extends Seeder
{
    /**
     * Seed a few demo projects (each with sub-pillars) for the Unijaya tenant so
     * the timesheet capture modal has real options on first load. Idempotent and
     * tenant-scoped explicitly (no tenant session in seeders).
     */
    public function run(): void
    {
        $tenant = Tenant::where('slug', 'unijaya')->first();
        if (! $tenant) {
            return;
        }

        $tid = $tenant->id;
        if (Project::where('tenant_id', $tid)->exists()) {
            return;
        }

        // [code, name, [sub-pillars...]]
        $projects = [
            ['KPT', 'KPT: RMS', ['Frontend', 'Backend', 'QA']],
            ['MITI', 'MITI: eABDC', ['Integration', 'Reporting']],
            ['KDN', 'KDN: iLPF', ['API', 'Migration']],
            ['INT', 'Internal', ['Code Review', 'Standups', 'Tooling']],
        ];

        foreach ($projects as $i => [$code, $name, $subPillars]) {
            $project = Project::create([
                'tenant_id' => $tid,
                'code' => $code,
                'name' => $name,
                'is_active' => true,
                'sort' => $i,
            ]);

            foreach ($subPillars as $j => $sp) {
                $project->subPillars()->create([
                    'tenant_id' => $tid,
                    'name' => $sp,
                    'is_active' => true,
                    'sort' => $j,
                ]);
            }
        }
    }
}
