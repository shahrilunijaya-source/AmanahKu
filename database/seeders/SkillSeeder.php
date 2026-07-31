<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\EmployeeSkill;
use App\Models\Skill;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

class SkillSeeder extends Seeder
{
    /**
     * Seed a small competency catalogue (8 skills across categories) plus varied
     * ratings for several of the Unijaya tenant's employees — some verified — so
     * the team matrix and gap analysis have real signal. Safe to re-run: skips if
     * that tenant already has skills, and guards against a missing tenant or empty
     * employee list. No tenant session exists in seeders, so tenant_id is set
     * explicitly.
     */
    public function run(): void
    {
        $tenant = Tenant::where('slug', 'unijaya')->first();
        if (! $tenant) {
            return;
        }

        $tid = $tenant->id;

        // Global scope is inactive in seeders, so scope to the tenant explicitly.
        if (Skill::where('tenant_id', $tid)->exists()) {
            return;
        }

        $employees = Employee::where('tenant_id', $tid)->orderBy('id')->get();
        if ($employees->isEmpty()) {
            return;
        }

        // [name, category, description]
        $catalogue = [
            ['Payroll Processing', 'Technical', 'Running statutory-compliant monthly payroll end to end.'],
            ['Excel / Spreadsheets', 'Technical', 'Formulas, pivot tables, and data modelling in spreadsheets.'],
            ['SQL', 'Technical', 'Querying and reporting against relational databases.'],
            ['Data Analysis', 'Technical', 'Turning raw HR/operational data into actionable insight.'],
            ['Stakeholder Communication', 'Communication', 'Clear, timely communication with internal and external stakeholders.'],
            ['Conflict Resolution', 'Communication', 'Mediating and de-escalating workplace disputes.'],
            ['Project Management', 'Leadership', 'Planning, scoping, and delivering cross-functional work.'],
            ['Recruitment', 'Domain', 'Sourcing, screening, and hiring the right talent.'],
        ];

        $skills = [];
        foreach ($catalogue as [$name, $category, $description]) {
            $skills[$name] = Skill::create([
                'tenant_id' => $tid,
                'name' => $name,
                'category' => $category,
                'description' => $description,
            ]);
        }

        // [employee index, skill name, level 1–5, verified]
        $ratings = [
            [0, 'Payroll Processing', 5, true],
            [0, 'Stakeholder Communication', 4, true],
            [0, 'Recruitment', 4, false],
            [0, 'Project Management', 4, true],
            [1, 'Payroll Processing', 4, true],
            [1, 'Excel / Spreadsheets', 5, false],
            [1, 'Recruitment', 3, false],
            [2, 'Project Management', 4, true],
            [2, 'Conflict Resolution', 3, false],
            [2, 'Stakeholder Communication', 4, false],
            [3, 'Excel / Spreadsheets', 4, true],
            [3, 'SQL', 2, false],
            [3, 'Data Analysis', 3, false],
            [4, 'SQL', 4, true],
            [4, 'Data Analysis', 4, false],
            [4, 'Excel / Spreadsheets', 3, false],
        ];

        foreach ($ratings as [$index, $skillName, $level, $verified]) {
            $employee = $employees->get($index);
            $skill = $skills[$skillName] ?? null;
            if (! $employee || ! $skill) {
                continue;
            }

            // Guard against duplicate (skill, employee) rows on re-runs.
            EmployeeSkill::updateOrCreate(
                [
                    'skill_id' => $skill->id,
                    'employee_id' => $employee->id,
                ],
                [
                    'tenant_id' => $tid,
                    'level' => $level,
                    'verified' => $verified,
                    'verified_by_id' => $verified ? $employees->first()->id : null,
                    'self_rated_at' => now(),
                ],
            );
        }
    }
}
