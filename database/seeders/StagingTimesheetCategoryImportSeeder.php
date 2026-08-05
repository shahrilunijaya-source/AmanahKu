<?php

namespace Database\Seeders;

use App\Models\Project;
use App\Models\ProjectSubPillar;
use App\Models\Tenant;
use App\Models\TimesheetCategory;
use Illuminate\Database\Seeder;

class StagingTimesheetCategoryImportSeeder extends Seeder
{
    /**
     * One-off import of staging's timesheet categories, projects, and project
     * sub-pillars into prod. Additive only: for each tenant, adds any staging
     * row whose name isn't already present (case-insensitive, sub-pillars scoped
     * to their project), appended after the tenant's current max sort. Existing
     * prod rows are never modified or removed.
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

        // Project name => sub-pillar names — from staging tenant 1, 2026-08-05.
        $stagingProjects = [
            'JKDM: MyStods' => ['Management', 'Technical', 'Meeting'],
            'JKDM: MyDLV' => ['Management', 'Meeting', 'Technical'],
            'KKM: NSFIRM' => ['Management', 'Meeting', 'Technical'],
            'SPA: IRIS' => ['Technical', 'Meeting', 'Management'],
            'MOTAC: TTMS' => ['Management', 'Meeting', 'Technical'],
            'KUSKOP: EPMS' => ['Technical', 'Meeting', 'Management'],
            'KDN: iLPF' => ['Technical', 'Meeting', 'Management'],
            'KKDW: Pendigitalan' => ['Management', 'Technical', 'Meeting'],
            'DOA: MyLRMP' => ['Management', 'Meeting', 'Technical'],
            'JBG: iGuaman' => ['Technical', 'Meeting', 'Management'],
            'DOSM: HIES/BA' => ['Technical', 'Management', 'Meeting'],
            'JSM: eACC' => ['Meeting', 'Management', 'Technical'],
            'InHouse Project X' => [],
            'Amanahku' => [],
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
     * @param  array<string, array<int, string>>  $stagingProjects
     */
    private function importProjects(int $tenantId, array $stagingProjects): void
    {
        $nextProjectSort = (int) Project::where('tenant_id', $tenantId)->max('sort');

        foreach ($stagingProjects as $projectName => $subPillars) {
            $project = Project::where('tenant_id', $tenantId)
                ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower(trim($projectName))])
                ->first();

            if (! $project) {
                $nextProjectSort++;

                $project = Project::create([
                    'tenant_id' => $tenantId,
                    'name' => $projectName,
                    'sort' => $nextProjectSort,
                    'is_active' => true,
                ]);
            }

            $existingSubPillarNames = ProjectSubPillar::where('project_id', $project->id)
                ->pluck('name')
                ->map(fn (string $name) => mb_strtolower(trim($name)))
                ->all();

            $nextSubPillarSort = (int) ProjectSubPillar::where('project_id', $project->id)->max('sort');

            foreach ($subPillars as $subPillarName) {
                if (in_array(mb_strtolower(trim($subPillarName)), $existingSubPillarNames, true)) {
                    continue;
                }

                $nextSubPillarSort++;

                ProjectSubPillar::create([
                    'tenant_id' => $tenantId,
                    'project_id' => $project->id,
                    'name' => $subPillarName,
                    'sort' => $nextSubPillarSort,
                    'is_active' => true,
                ]);
            }
        }
    }
}
