<?php

namespace Database\Seeders;

use App\Models\Project;
use App\Models\Tenant;
use App\Models\TimesheetCategory;
use Illuminate\Database\Seeder;

class StagingTimesheetCategoryImportSeeder extends Seeder
{
    /**
     * One-off import of staging's timesheet categories and projects into prod.
     * Additive only: for each tenant, adds any staging row whose name isn't
     * already present (case-insensitive), appended after the tenant's current
     * max sort. Existing prod rows are never modified or removed.
     */
    public function run(): void
    {
        // [name_en, name_ms, requires_project] — from staging tenant 1, 2026-08-05.
        $stagingCategories = [
            ['Account & Finance', null, false],
            ['HR', 'HR', false],
            ['Development', 'Development', true],
            ['Maintenance', 'Maintenance', true],
            ['InHouse Project', 'InHouse Project', true],
            ['Study & Research', 'Kajian & Penyelidikan', false],
            ['Office Matters', 'Perihal Pejabat', false],
            ['On Leave', 'Cuti', false],
            ['Public Holiday', 'Cuti Umum', false],
        ];

        // Project names — from staging tenant 1, 2026-08-05. Sub-pillars are no
        // longer per project (see the sub_pillars migration), so this import only
        // creates the projects; the shared list is seeded by ProjectSeeder.
        $stagingProjects = [
            'JKDM: MyStods', 'JKDM: MyDLV', 'KKM: NSFIRM', 'SPA: IRIS', 'MOTAC: TTMS',
            'KUSKOP: EPMS', 'KDN: iLPF', 'KKDW: Pendigitalan', 'DOA: MyLRMP',
            'JBG: iGuaman', 'DOSM: HIES/BA', 'JSM: eACC', 'InHouse Project X', 'Amanahku',
        ];

        foreach (Tenant::all() as $tenant) {
            $this->importCategories($tenant->id, $stagingCategories);
            $this->importProjects($tenant->id, $stagingProjects);
        }
    }

    /**
     * @param  array<int, array{0: string, 1: ?string, 2: bool}>  $stagingCategories
     */
    private function importCategories(int $tenantId, array $stagingCategories): void
    {
        $existingNames = TimesheetCategory::where('tenant_id', $tenantId)
            ->pluck('name')
            ->map(fn (string $name) => mb_strtolower(trim($name)))
            ->all();

        $nextSort = (int) TimesheetCategory::where('tenant_id', $tenantId)->max('sort');

        foreach ($stagingCategories as [$en, $ms, $requiresProject]) {
            if (in_array(mb_strtolower(trim($en)), $existingNames, true)) {
                continue;
            }

            $nextSort++;

            TimesheetCategory::create([
                'tenant_id' => $tenantId,
                'name' => $en,
                'name_ms' => $ms,
                'requires_project' => $requiresProject,
                'sort' => $nextSort,
                'is_active' => true,
            ]);
        }
    }

    /**
     * @param  array<int, string>  $stagingProjects
     */
    private function importProjects(int $tenantId, array $stagingProjects): void
    {
        $nextProjectSort = (int) Project::where('tenant_id', $tenantId)->max('sort');

        foreach ($stagingProjects as $projectName) {
            $exists = Project::where('tenant_id', $tenantId)
                ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower(trim($projectName))])
                ->exists();

            if ($exists) {
                continue;
            }

            $nextProjectSort++;

            Project::create([
                'tenant_id' => $tenantId,
                'name' => $projectName,
                'sort' => $nextProjectSort,
                'is_active' => true,
            ]);
        }
    }
}
