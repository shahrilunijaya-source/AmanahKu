<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\TravelRequest;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class TravelSeeder extends Seeder
{
    /**
     * Seed ~3 travel requests across statuses for the first tenant's employees.
     * Safe to re-run: skips if the first tenant already has travel requests, and
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

        if (TravelRequest::where('tenant_id', $tid)->exists()) {
            return;
        }

        $employees = Employee::where('tenant_id', $tid)->orderBy('id')->take(4)->get();
        if ($employees->isEmpty()) {
            return;
        }

        // [requester idx, destination, purpose, departOffset, returnOffset, transport, cost, status, approverIdx|null]
        $plan = [
            [0, 'Kuala Lumpur — Client HQ', 'On-site requirements workshop with the client project team.', 5, 7, 'flight', 1850.00, 'submitted', null],
            [1, 'Penang — Regional Branch', 'Quarterly branch review and team alignment session.', -14, -12, 'car', 620.00, 'approved', 0],
            [2, 'Singapore — Industry Conference', 'Attend the regional HR technology conference.', -30, -27, 'flight', 3400.00, 'rejected', 0],
        ];

        foreach ($plan as $row) {
            $requester = $employees->get($row[0]);
            if (! $requester) {
                continue;
            }

            $decided = $row[7] !== 'submitted';

            TravelRequest::create([
                'tenant_id' => $tid,
                'employee_id' => $requester->id,
                'destination' => $row[1],
                'purpose' => $row[2],
                'depart_date' => Carbon::now()->addDays($row[3])->toDateString(),
                'return_date' => Carbon::now()->addDays($row[4])->toDateString(),
                'transport' => $row[5],
                'estimated_cost' => $row[6],
                'status' => $row[7],
                'approved_by_employee_id' => $decided && $row[8] !== null ? $employees->get($row[8])?->id : null,
                'decided_at' => $decided ? Carbon::now() : null,
            ]);
        }
    }
}
