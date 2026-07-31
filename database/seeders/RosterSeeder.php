<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\Shift;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

class RosterSeeder extends Seeder
{
    /**
     * Seed ~6 shifts across the current week for existing employees of the first
     * tenant. Safe to re-run: skips entirely if the first tenant already has shifts,
     * and guards against tenants with no employees.
     */
    public function run(): void
    {
        $tenant = Tenant::query()->orderBy('id')->first();
        if (! $tenant) {
            return;
        }

        $tid = $tenant->id;

        // Idempotent-ish: don't duplicate on re-run (global scope is inactive in seeders,
        // so scope the count to the tenant explicitly).
        if (Shift::where('tenant_id', $tid)->exists()) {
            return;
        }

        $employees = Employee::where('tenant_id', $tid)->orderBy('id')->take(4)->get();
        if ($employees->isEmpty()) {
            return;
        }

        $weekStart = now()->startOfWeek();

        // [employee index, day offset (Mon=0), start, end, location, status]
        $plan = [
            [0, 0, '09:00', '18:00', 'PJ HQ', 'confirmed'],
            [0, 2, '09:00', '18:00', 'PJ HQ', 'scheduled'],
            [1, 0, '08:00', '17:00', 'Client site', 'confirmed'],
            [1, 3, '08:00', '17:00', 'Seremban 2', 'scheduled'],
            [2, 1, '10:00', '19:00', 'PJ HQ', 'scheduled'],
            [3, 4, '08:30', '17:30', 'Klang', 'confirmed'],
        ];

        foreach ($plan as $row) {
            $employee = $employees->get($row[0]);
            if (! $employee) {
                continue;
            }

            Shift::create([
                'tenant_id' => $tid,
                'employee_id' => $employee->id,
                'date' => $weekStart->copy()->addDays($row[1])->toDateString(),
                'start_time' => $row[2],
                'end_time' => $row[3],
                'location' => $row[4],
                'status' => $row[5],
            ]);
        }
    }
}
