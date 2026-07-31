<?php

namespace Database\Seeders;

use App\Models\DisciplinaryCase;
use App\Models\Employee;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

class CaseSeeder extends Seeder
{
    /**
     * Seed ~3 disciplinary/grievance cases across statuses for the first tenant's
     * employees. Safe to re-run: skips if the first tenant already has cases, and
     * guards against tenants with no employees. tenant_id is set explicitly because
     * the global scope is inactive in seeders (no session).
     */
    public function run(): void
    {
        $tenant = Tenant::query()->orderBy('id')->first();
        if (! $tenant) {
            return;
        }

        $tid = $tenant->id;

        if (DisciplinaryCase::where('tenant_id', $tid)->exists()) {
            return;
        }

        $employees = Employee::where('tenant_id', $tid)->orderBy('id')->take(4)->get();
        if ($employees->isEmpty()) {
            return;
        }

        // [subject index, openedBy index, type, severity, subject, details, status, outcome|null]
        $plan = [
            [0, 1, 'warning', 'medium', 'Repeated lateness — first written warning', 'Recorded late arrivals on five occasions over the past month without prior notice.', 'open', null],
            [1, 1, 'investigation', 'high', 'Alleged misuse of company assets', 'Reported use of a company laptop for unauthorised external work. Under review.', 'investigating', null],
            [2, 1, 'grievance', 'low', 'Grievance regarding shift allocation', 'Employee raised concern about uneven weekend shift distribution within the team.', 'resolved', 'Shift rota revised and agreed with the team. Grievance closed amicably.'],
        ];

        foreach ($plan as $row) {
            $subject = $employees->get($row[0]);
            if (! $subject) {
                continue;
            }

            DisciplinaryCase::create([
                'tenant_id' => $tid,
                'employee_id' => $subject->id,
                'opened_by_employee_id' => $employees->get($row[1])?->id,
                'type' => $row[2],
                'severity' => $row[3],
                'subject' => $row[4],
                'details' => $row[5],
                'status' => $row[6],
                'outcome' => $row[7],
            ]);
        }
    }
}
