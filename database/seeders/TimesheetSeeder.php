<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\Timesheet;
use App\Models\TimesheetCategory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

class TimesheetSeeder extends Seeder
{
    /**
     * Seed one submitted timesheet per named employee for LAST week.
     *
     * Last week, not a fixed date, because Track's weekly cadence reads the week the
     * PM is reporting on and only counts 'submitted'/'approved' timesheets — a draft
     * or a stale hardcoded week shows up as an empty week on the Track side.
     *
     * Employees, projects and categories are resolved by name against whatever the
     * tenant already has, so this runs on top of a restored staging database rather
     * than inventing its own org chart. Anything it cannot resolve is skipped.
     *
     * Re-runnable: the seeded week is dropped for the named employees before it is
     * rebuilt, so figures never double up.
     */
    public function run(): void
    {
        $tenant = Tenant::where('slug', 'unijaya-resources-sdn-bhd')->first() ?? Tenant::orderBy('id')->first();
        if (! $tenant) {
            return;
        }

        $tid = $tenant->id;
        $weekStart = CarbonImmutable::now()->startOfWeek()->subWeek();
        $hoursPerDay = (float) config('manday.hours_per_day', 8);

        // Global scope is inactive in seeders, so scope to the tenant explicitly.
        $categories = TimesheetCategory::where('tenant_id', $tid)->get()->keyBy('name');
        $projects = Project::where('tenant_id', $tid)->get()->keyBy('name');

        // [employee name => [ [categoryName, projectName|null, percentage per weekday], ... ]]
        // Each employee's percentages sum to 100 across a day; the same split repeats
        // Monday to Friday, which is how a steady delivery week actually looks.
        $plan = [
            'Haryati Binti Moktar' => [['Development', 'KDN: iLPF', 50], ['Maintenance', 'JKDM: MyStods', 50]],
            'Ahmad Kussairi Bin Sutikno' => [['Maintenance', 'MOTAC: TTMS', 100]],
            'Nurin Saffa Binti Safuan' => [['Development', 'KDN: iLPF', 100]],
            'Anasuha Binti Mohd Ali' => [['Maintenance', 'JKDM: MyStods', 60], ['Maintenance', 'MOTAC: TTMS', 40]],
            'Mohamad Syafiq Azwan Bin Mustafa' => [['Development', 'KDN: iLPF', 100]],
            'Muhammad Hafiz Ruslan' => [['Development', 'KDN: iLPF', 80], ['InHouse Project', 'InHouse Project X', 20]],
            'Lee Weng Yew' => [['Maintenance', 'JKDM: MyStods', 100]],
            'Muhammad Shukri Bin Aman' => [['Maintenance', 'MOTAC: TTMS', 100]],
            'Nurhidayah Abdul Halim' => [['Maintenance', 'JBG: iGuaman', 100]],
            'Muhamad Rubmin Bin Muhamad' => [['Maintenance', 'JBG: iGuaman', 60], ['Development', 'KDN: iLPF', 40]],
        ];

        $employees = Employee::where('tenant_id', $tid)
            ->whereIn('name', array_keys($plan))
            ->get()
            ->keyBy('name');

        Timesheet::where('tenant_id', $tid)
            ->where('week_start', $weekStart->toDateString())
            ->whereIn('employee_id', $employees->pluck('id'))
            ->delete();

        $label = 'Week '.$weekStart->isoWeek().' · '.$weekStart->format('j').'–'.$weekStart->addDays(6)->format('j M');

        foreach ($plan as $name => $lines) {
            $employee = $employees->get($name);
            if (! $employee) {
                continue;
            }

            $timesheet = Timesheet::create([
                'tenant_id' => $tid,
                'employee_id' => $employee->id,
                'week_start' => $weekStart->toDateString(),
                'week_label' => $label,
                'status' => 'submitted',
                'submitted_at' => now(),
            ]);

            foreach (range(0, 4) as $dayOffset) {
                $date = $weekStart->addDays($dayOffset)->toDateString();

                foreach ($lines as [$catName, $projName, $pct]) {
                    $category = $categories->get($catName);
                    $project = $projects->get($projName);
                    if (! $project) {
                        continue;
                    }

                    $timesheet->entries()->create([
                        'tenant_id' => $tid,
                        'entry_date' => $date,
                        'category_id' => $category?->id,
                        'project_id' => $project->id,
                        'percentage' => $pct,
                        // Legacy readable fallback for any code that still reads `project`.
                        'project' => trim($catName.' — '.$projName),
                        // Hours derived from percentage so manday RM costing works on seeded data.
                        'hours' => round($pct / 100 * $hoursPerDay, 2),
                    ]);
                }
            }

            // Roll entry hours up into total_hours so timesheet/report costing is correct.
            $timesheet->recomputeTotal();
        }
    }
}
