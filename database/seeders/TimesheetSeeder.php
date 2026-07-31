<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Employee;
use App\Models\Position;
use App\Models\Project;
use App\Models\StaffLevel;
use App\Models\Tenant;
use App\Models\Timesheet;
use App\Models\TimesheetCategory;
use Illuminate\Database\Seeder;

class TimesheetSeeder extends Seeder
{
    /**
     * Seed 2-3 weekly timesheets for the Unijaya tenant's employees using the
     * category / project / sub-pillar / percentage allocation model. Every day's
     * entries sum to 100%. Safe to re-run: skips if that tenant already has
     * timesheets. No tenant session exists in seeders, so tenant_id is explicit.
     * Runs AFTER TimesheetCategorySeeder + ProjectSeeder.
     */
    public function run(): void
    {
        $tenant = Tenant::where('slug', 'unijaya')->first();
        if (! $tenant) {
            return;
        }

        $tid = $tenant->id;

        // Global scope is inactive in seeders, so scope to the tenant explicitly.
        if (Timesheet::where('tenant_id', $tid)->exists()) {
            return;
        }

        $employees = Employee::where('tenant_id', $tid)->orderBy('id')->take(3)->get();
        if ($employees->isEmpty()) {
            return;
        }

        // Give each seeded owner a salary band so the cost report shows real RM, not just
        // person-days. Seed a small rate card first if the tenant has none (real tenants
        // build theirs via Position admin), then assign bands cycling down the card.
        $bands = Position::where('tenant_id', $tid)->orderByDesc('max_salary')->get();
        if ($bands->isEmpty()) {
            // Rate-card axes are FK lookups; ensure the department + levels exist first.
            $dept = Department::firstOrCreate(['tenant_id' => $tid, 'name' => 'Operation']);
            $levelId = fn (string $name) => StaffLevel::firstOrCreate(['tenant_id' => $tid, 'name' => $name])->id;

            $bands = collect([
                ['staff_level_id' => $levelId('Manager'), 'title' => 'Engineering Lead', 'max_salary' => 10000],
                ['staff_level_id' => $levelId('Senior'), 'title' => 'Senior Engineer', 'max_salary' => 7000],
                ['staff_level_id' => $levelId('Executive'), 'title' => 'Engineer', 'max_salary' => 4500],
            ])->map(fn (array $b) => Position::create(['tenant_id' => $tid, 'department_id' => $dept->id] + $b));
        }
        $employees->values()->each(function (Employee $emp, int $i) use ($bands): void {
            if (! $emp->position_id) {
                $emp->update(['position_id' => $bands[$i % $bands->count()]->id]);
            }
        });

        $categories = TimesheetCategory::where('tenant_id', $tid)->get()->keyBy('name');
        $projects = Project::where('tenant_id', $tid)->with('subPillars')->get()->keyBy('name');
        if ($categories->isEmpty() || $projects->isEmpty()) {
            return; // category/project seeders must run first
        }

        // Hours derive from percentage so manday RM costing works on seeded data too
        // (one full day at 100% = a full working day), matching the real save path.
        $hoursPerDay = (float) config('manday.hours_per_day', 8);

        // [employee index, week_start, week_label, status,
        //   [ [date, categoryName, projectName|null, subPillarName|null, percentage, descriptionHtml|null], ... ] ]
        $plan = [
            [0, '2026-06-15', 'Week 25 · 15–21 Jun', 'submitted', [
                ['2026-06-15', 'Development', 'KDN: iLPF', 'API', 100, '<p>Sprint planning + endpoints</p>'],
                ['2026-06-16', 'Development', 'KDN: iLPF', 'API', 75, '<p>Endpoint build-out</p>'],
                ['2026-06-16', 'Others', null, null, 25, '<p>PR reviews for the team</p>'],
                ['2026-06-17', 'Others', null, null, 100, '<p>PR reviews + standups</p>'],
                ['2026-06-18', 'Development', 'KDN: iLPF', 'Migration', 100, null],
                ['2026-06-19', 'Development', 'KPT: RMS', 'Frontend', 60, '<p>Requirements workshop</p>'],
                ['2026-06-19', 'Study & Research', null, null, 40, '<p>Spike on the new framework</p>'],
            ]],
            [1, '2026-06-08', 'Week 24 · 8–14 Jun', 'approved', [
                ['2026-06-08', 'Development', 'KPT: RMS', 'Frontend', 100, null],
                ['2026-06-09', 'Development', 'KPT: RMS', 'Frontend', 100, '<p>Dashboard widgets</p>'],
                ['2026-06-10', 'Maintenance', 'KPT: RMS', 'Backend', 50, '<p>Bug fixes</p>'],
                ['2026-06-10', 'Development', 'KPT: RMS', 'Frontend', 50, null],
                ['2026-06-11', 'Others', null, null, 100, '<p>Standups + admin</p>'],
            ]],
            [2, '2026-06-15', 'Week 25 · 15–21 Jun', 'draft', [
                ['2026-06-15', 'Maintenance', 'MITI: eABDC', 'Reporting', 100, '<p>Regression pass</p>'],
                ['2026-06-16', 'Maintenance', 'MITI: eABDC', 'Reporting', 100, null],
                ['2026-06-17', 'Sales', null, null, 100, '<p>Shell S2 walkthrough</p>'],
            ]],
        ];

        foreach ($plan as $row) {
            $employee = $employees->get($row[0]);
            if (! $employee) {
                continue;
            }

            $timesheet = Timesheet::create([
                'tenant_id' => $tid,
                'employee_id' => $employee->id,
                'week_start' => $row[1],
                'week_label' => $row[2],
                'status' => $row[3],
                'submitted_at' => $row[3] === 'draft' ? null : now(),
                'decided_at' => $row[3] === 'approved' ? now() : null,
                'decided_by_id' => $row[3] === 'approved' ? $employee->id : null,
            ]);

            foreach ($row[4] as [$date, $catName, $projName, $subName, $pct, $desc]) {
                $category = $categories->get($catName);
                $project = $projName ? $projects->get($projName) : null;
                $subPillar = $project && $subName
                    ? $project->subPillars->firstWhere('name', $subName)
                    : null;

                $timesheet->entries()->create([
                    'tenant_id' => $tid,
                    'entry_date' => $date,
                    'category_id' => $category?->id,
                    'project_id' => $project?->id,
                    'sub_pillar_id' => $subPillar?->id,
                    'percentage' => $pct,
                    'description' => $desc,
                    // Legacy readable fallback for any code that still reads `project`.
                    'project' => trim(($catName ?? '').($projName ? ' — '.$projName : '')),
                    // Hours derived from percentage so manday RM costing works on seeded data.
                    'hours' => round($pct / 100 * $hoursPerDay, 2),
                ]);
            }

            // Roll entry hours up into total_hours so timesheet/report costing is correct.
            $timesheet->recomputeTotal();
        }
    }
}
