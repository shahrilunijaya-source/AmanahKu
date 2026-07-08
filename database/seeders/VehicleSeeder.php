<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\Vehicle;
use App\Models\VehicleBooking;
use Illuminate\Database\Seeder;

class VehicleSeeder extends Seeder
{
    /**
     * Seed a small fleet + a couple of non-overlapping bookings for the first tenant.
     * Safe to re-run: skips if the first tenant already has vehicles, and guards against
     * tenants with no employees. No tenant session exists during seeding, so tenant_id
     * is set explicitly.
     */
    public function run(): void
    {
        $tenant = Tenant::query()->orderBy('id')->first();
        if (! $tenant) {
            return;
        }

        $tid = $tenant->id;

        // Global scope is inactive during seeding — scope the existence check explicitly.
        if (Vehicle::where('tenant_id', $tid)->exists()) {
            return;
        }

        // [name, registration_no, type, seats]
        $vehiclePlan = [
            ['Toyota Hilux', 'WXY 1234', 'truck', 5],
            ['Toyota Vios', 'WMA 8821', 'car', 5],
            ['Hino Lorry', 'WTC 1102', 'truck', 3],
        ];

        $vehicles = [];
        foreach ($vehiclePlan as $row) {
            $vehicles[] = Vehicle::create([
                'tenant_id' => $tid,
                'name' => $row[0],
                'registration_no' => $row[1],
                'type' => $row[2],
                'seats' => $row[3],
                'is_active' => true,
            ]);
        }

        $employees = Employee::where('tenant_id', $tid)->orderBy('id')->take(2)->get();
        if ($employees->isEmpty()) {
            return;
        }

        $tomorrow = now()->addDay()->startOfDay();

        // Non-overlapping windows: different vehicles / times so the seed never trips the
        // conflict check.
        // [vehicle index, employee index, starts_at, ends_at, purpose, destination]
        $bookingPlan = [
            [0, 0, $tomorrow->copy()->setTime(9, 0), $tomorrow->copy()->setTime(12, 0), 'Site inspection', 'Klang depot'],
            [1, 1, $tomorrow->copy()->setTime(9, 0), $tomorrow->copy()->setTime(11, 0), 'Client visit', 'KL Sentral'],
            [0, 1, $tomorrow->copy()->setTime(14, 0), $tomorrow->copy()->setTime(17, 0), 'Equipment pickup', 'Shah Alam'],
        ];

        foreach ($bookingPlan as $row) {
            $vehicle = $vehicles[$row[0]] ?? null;
            $employee = $employees->get($row[1]);
            if (! $vehicle || ! $employee) {
                continue;
            }

            VehicleBooking::create([
                'tenant_id' => $tid,
                'vehicle_id' => $vehicle->id,
                'employee_id' => $employee->id,
                'starts_at' => $row[2],
                'ends_at' => $row[3],
                'purpose' => $row[4],
                'destination' => $row[5],
                'status' => 'confirmed',
            ]);
        }
    }
}
