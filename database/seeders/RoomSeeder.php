<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\MeetingRoom;
use App\Models\RoomBooking;
use App\Models\Tenant;
use Illuminate\Database\Seeder;

class RoomSeeder extends Seeder
{
    /**
     * Seed ~3 meeting rooms + a couple of non-overlapping bookings for the first tenant.
     * Safe to re-run: skips if the first tenant already has rooms, and guards against
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
        if (MeetingRoom::where('tenant_id', $tid)->exists()) {
            return;
        }

        // [name, location, capacity]
        $roomPlan = [
            ['Boardroom A', 'Level 12, PJ HQ', 14],
            ['Huddle Room 1', 'Level 8, PJ HQ', 4],
            ['Training Room B', 'Level 3, PJ HQ', 24],
        ];

        $rooms = [];
        foreach ($roomPlan as $row) {
            $rooms[] = MeetingRoom::create([
                'tenant_id' => $tid,
                'name' => $row[0],
                'location' => $row[1],
                'capacity' => $row[2],
                'active' => true,
            ]);
        }

        $employees = Employee::where('tenant_id', $tid)->orderBy('id')->take(2)->get();
        if ($employees->isEmpty()) {
            return;
        }

        $today = now()->toDateString();
        $tomorrow = now()->addDay()->toDateString();

        // Non-overlapping slots: different rooms / times so the seed never trips the
        // conflict check.
        // [room index, employee index, date, start, end, title]
        $bookingPlan = [
            [0, 0, $today, '09:00', '10:00', 'Leadership sync'],
            [0, 0, $today, '11:00', '12:00', 'Vendor review'],
            [1, 1, $today, '14:00', '15:00', '1:1 catch-up'],
            [2, 0, $tomorrow, '10:00', '12:00', 'Onboarding workshop'],
        ];

        foreach ($bookingPlan as $row) {
            $room = $rooms[$row[0]] ?? null;
            $employee = $employees->get($row[1]);
            if (! $room || ! $employee) {
                continue;
            }

            RoomBooking::create([
                'tenant_id' => $tid,
                'meeting_room_id' => $room->id,
                'employee_id' => $employee->id,
                'date' => $row[2],
                'start_time' => $row[3],
                'end_time' => $row[4],
                'title' => $row[5],
                'status' => 'confirmed',
            ]);
        }
    }
}
