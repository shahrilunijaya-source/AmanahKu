<?php

namespace Database\Seeders;

use App\Models\BenefitEnrollment;
use App\Models\BenefitPlan;
use App\Models\Employee;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

class BenefitSeeder extends Seeder
{
    /**
     * Seed ~3 benefit plans + a few enrollments for the first tenant's employees.
     * Safe to re-run: skips entirely if the first tenant already has plans, and
     * guards against tenants with no employees. No tenant session exists during
     * seeding, so tenant_id is set explicitly.
     */
    public function run(): void
    {
        $tenant = Tenant::query()->orderBy('id')->first();
        if (! $tenant) {
            return;
        }

        $tid = $tenant->id;

        // Global scope is inactive in seeders, so scope to the tenant explicitly.
        if (BenefitPlan::where('tenant_id', $tid)->exists()) {
            return;
        }

        $employees = Employee::where('tenant_id', $tid)->orderBy('id')->get();
        if ($employees->isEmpty()) {
            return;
        }

        $medical = BenefitPlan::create([
            'tenant_id' => $tid,
            'name' => 'AIA Medical Premier',
            'type' => 'medical',
            'provider' => 'AIA',
            'coverage' => 'Inpatient, outpatient, and specialist coverage up to RM150,000/year.',
            'monthly_cost' => 180.00,
            'active' => true,
        ]);

        $dental = BenefitPlan::create([
            'tenant_id' => $tid,
            'name' => 'Great Eastern Dental Care',
            'type' => 'dental',
            'provider' => 'Great Eastern',
            'coverage' => 'Two cleanings a year plus RM800 toward fillings and extractions.',
            'monthly_cost' => 45.00,
            'active' => true,
        ]);

        $life = BenefitPlan::create([
            'tenant_id' => $tid,
            'name' => 'Prudential Term Life',
            'type' => 'life',
            'provider' => 'Prudential',
            'coverage' => 'Term life cover of 36× monthly salary with accidental-death rider.',
            'monthly_cost' => 60.00,
            'active' => true,
        ]);

        // [employee index, plan, status, dependents]
        $plan = [
            [0, $medical, 'enrolled', 2],
            [0, $dental, 'enrolled', 0],
            [1, $medical, 'enrolled', 0],
            [1, $life, 'waived', 0],
            [2, $medical, 'waived', 0],
            [3, $life, 'enrolled', 1],
        ];

        foreach ($plan as $row) {
            $employee = $employees->get($row[0]);
            if (! $employee) {
                continue;
            }

            $enrolled = $row[2] === 'enrolled';

            // Guard against duplicate (plan, employee) enrollments on re-runs.
            BenefitEnrollment::updateOrCreate(
                [
                    'benefit_plan_id' => $row[1]->id,
                    'employee_id' => $employee->id,
                ],
                [
                    'tenant_id' => $tid,
                    'status' => $row[2],
                    'dependents' => $enrolled ? $row[3] : 0,
                    'enrolled_at' => $enrolled ? now()->toDateString() : null,
                ],
            );
        }
    }
}
