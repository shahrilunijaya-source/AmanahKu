<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\Ticket;
use Illuminate\Database\Seeder;

class HelpdeskSeeder extends Seeder
{
    /**
     * Seed ~5 tickets across statuses for the first tenant's employees.
     * Safe to re-run: skips if the first tenant already has tickets, and guards
     * against tenants with no employees. tenant_id is set explicitly because the
     * global scope is inactive in seeders (no session).
     */
    public function run(): void
    {
        $tenant = Tenant::query()->orderBy('id')->first();
        if (! $tenant) {
            return;
        }

        $tid = $tenant->id;

        if (Ticket::where('tenant_id', $tid)->exists()) {
            return;
        }

        $employees = Employee::where('tenant_id', $tid)->orderBy('id')->take(4)->get();
        if ($employees->isEmpty()) {
            return;
        }

        // [raiser index, category, priority, subject, description, status, assigneeIdx|null, resolution|null]
        $plan = [
            [0, 'IT', 'high', 'VPN keeps disconnecting', 'My VPN drops every few minutes when working from home, breaking remote sessions.', 'open', null, null],
            [1, 'IT', 'urgent', 'Laptop will not boot', 'Laptop shows a black screen after the login chime. Cannot start work.', 'in_progress', 0, null],
            [2, 'Facilities', 'medium', 'Aircon too cold on level 3', 'The aircon on level 3 is set very low and the team is uncomfortable.', 'in_progress', 0, null],
            [3, 'HR', 'low', 'Update emergency contact', 'Please update my emergency contact number on file.', 'resolved', 0, 'Emergency contact updated in the HR system.'],
            [0, 'Other', 'medium', 'Request standing desk', 'Requesting a standing desk for ergonomic reasons.', 'closed', 0, 'Standing desk approved and delivered to the workstation.'],
        ];

        foreach ($plan as $row) {
            $raiser = $employees->get($row[0]);
            if (! $raiser) {
                continue;
            }

            Ticket::create([
                'tenant_id' => $tid,
                'employee_id' => $raiser->id,
                'assignee_employee_id' => $row[6] !== null ? $employees->get($row[6])?->id : null,
                'category' => $row[1],
                'priority' => $row[2],
                'subject' => $row[3],
                'description' => $row[4],
                'status' => $row[5],
                'resolution' => $row[7],
            ]);
        }
    }
}
