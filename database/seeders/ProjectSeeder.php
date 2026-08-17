<?php

namespace Database\Seeders;

use App\Models\Project;
use App\Models\SubPillar;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

class ProjectSeeder extends Seeder
{
    /**
     * Seed a few demo projects and the shared sub-pillar list for the Unijaya
     * tenant so the timesheet capture modal has real options on first load.
     * Idempotent and tenant-scoped explicitly (no tenant session in seeders).
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

        // [code, name]
        $projects = [
            ['KPT', 'KPT: RMS'],
            ['MITI', 'MITI: eABDC'],
            ['KDN', 'KDN: iLPF'],
            ['INT', 'Internal'],
        ];

        foreach ($projects as $i => [$code, $name]) {
            Project::create([
                'tenant_id' => $tid,
                'code' => $code,
                'name' => $name,
                'is_active' => true,
                'sort' => $i,
            ]);
        }

        // One shared list, used by every project — the shape Unijaya actually uses.
        foreach (['Management', 'Meeting', 'Technical'] as $j => $name) {
            SubPillar::firstOrCreate(
                ['tenant_id' => $tid, 'name' => $name],
                ['is_active' => true, 'sort' => $j],
            );
        }
    }
}
