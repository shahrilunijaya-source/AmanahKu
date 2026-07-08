<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Models\TimesheetCategory;
use Illuminate\Database\Seeder;

class TimesheetCategorySeeder extends Seeder
{
    /**
     * Seed the default timesheet categories for every tenant. Development and
     * Maintenance require a project; the rest stand alone. Idempotent: a tenant
     * that already has categories is skipped. No tenant session exists in
     * seeders, so tenant_id is set explicitly.
     */
    public function run(): void
    {
        // [name_en, name_ms, requires_project]
        $defaults = [
            ['Development', 'Pembangunan', true],
            ['Maintenance', 'Penyelenggaraan', true],
            ['Sales', 'Jualan', false],
            ['Public Holiday', 'Cuti Umum', false],
            ['Others', 'Lain-lain', false],
            ['Study & Research', 'Kajian & Penyelidikan', false],
            ['On Leave', 'Bercuti', false],
        ];

        foreach (Tenant::all() as $tenant) {
            if (TimesheetCategory::where('tenant_id', $tenant->id)->exists()) {
                continue;
            }

            foreach ($defaults as $i => [$en, $ms, $requiresProject]) {
                TimesheetCategory::create([
                    'tenant_id' => $tenant->id,
                    'name' => $en,
                    'name_ms' => $ms,
                    'requires_project' => $requiresProject,
                    'sort' => $i,
                    'is_active' => true,
                ]);
            }
        }
    }
}
