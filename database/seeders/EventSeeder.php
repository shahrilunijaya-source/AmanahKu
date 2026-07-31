<?php

namespace Database\Seeders;

use App\Models\CompanyEvent;
use App\Models\Employee;
use App\Models\EventRsvp;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

class EventSeeder extends Seeder
{
    /**
     * Seed ~3 upcoming company events + a few RSVPs for the first tenant.
     * Idempotent-ish: skips if events already exist for the tenant, and guards when
     * there is no tenant or no employees yet. No tenant session exists during seeding,
     * so tenant_id is set explicitly.
     */
    public function run(): void
    {
        $tenant = Tenant::orderBy('id')->first();
        if (! $tenant) {
            return;
        }

        if (CompanyEvent::where('tenant_id', $tenant->id)->exists()) {
            return;
        }

        $employees = Employee::where('tenant_id', $tenant->id)->orderBy('id')->get();
        if ($employees->isEmpty()) {
            return;
        }

        $author = $employees->first();

        // [title, type, days from now, time, location, description]
        $plan = [
            ['Q3 Company Town Hall', 'townhall', 7, '3:00 PM', 'PJ HQ, Level 12 Auditorium', 'Quarterly business update and open Q&A with the leadership team.'],
            ['Excel Power-User Workshop', 'training', 12, '10:00 AM', 'Training Room B', 'Hands-on session on pivot tables, lookups and dashboards.'],
            ['Family Day & Sports Carnival', 'social', 21, '8:30 AM', 'Shah Alam Sports Complex', 'Bring the family — games, food trucks and team activities.'],
        ];

        $responses = ['going', 'going', 'maybe', 'going', 'declined', 'maybe'];

        foreach ($plan as $row) {
            $event = CompanyEvent::create([
                'tenant_id' => $tenant->id,
                'title' => $row[0],
                'type' => $row[1],
                'event_date' => now()->addDays($row[2])->toDateString(),
                'start_time' => $row[3],
                'location' => $row[4],
                'description' => $row[5],
                'created_by_employee_id' => $author->id,
            ]);

            foreach ($employees as $i => $emp) {
                if ($i >= count($responses)) {
                    break;
                }
                EventRsvp::create([
                    'tenant_id' => $tenant->id,
                    'company_event_id' => $event->id,
                    'employee_id' => $emp->id,
                    'response' => $responses[$i],
                ]);
            }
        }
    }
}
