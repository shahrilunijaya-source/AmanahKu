<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\MeetingRoom;
use App\Models\RoomBooking;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Feature coverage for the Meeting room booking module.
 * Harness (setUp / actingInTenant / hrActor) copied from CoreWritePathsTest.
 */
class RoomTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Tenant $tenant;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create(['name' => 'Demo', 'email' => 'demo@example.com', 'password' => Hash::make('password')]);
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $this->user->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $this->employee = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->user->id,
            'name' => 'Demo', 'status' => 'active', 'workload' => 'green',
        ]);
    }

    private function actingInTenant(): self
    {
        $this->actingAs($this->user)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    private function hrActor(): User
    {
        $hr = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $hr->id,
            'name' => 'Boss', 'status' => 'active', 'workload' => 'green',
        ]);

        return $hr;
    }

    private function room(string $name = 'Boardroom A'): MeetingRoom
    {
        return MeetingRoom::create([
            'tenant_id' => $this->tenant->id,
            'name' => $name,
            'location' => 'Level 12',
            'capacity' => 10,
            'active' => true,
        ]);
    }

    // ── Booking ───────────────────────────────────────────────────

    public function test_employee_books_an_available_slot(): void
    {
        // Arrange
        $room = $this->room();

        // Act
        $response = $this->actingInTenant()->post('/app/rooms/book', [
            'meeting_room_id' => $room->id,
            'date' => now()->toDateString(),
            'start_time' => '09:00',
            'end_time' => '10:00',
            'title' => 'Sprint planning',
        ]);

        // Assert
        $response->assertRedirect();
        $this->assertDatabaseHas('room_bookings', [
            'tenant_id' => $this->tenant->id,
            'meeting_room_id' => $room->id,
            'employee_id' => $this->employee->id,
            'title' => 'Sprint planning',
            'status' => 'confirmed',
        ]);
    }

    public function test_overlapping_booking_for_same_room_is_rejected(): void
    {
        // Arrange — an existing confirmed booking 09:00–10:00.
        $room = $this->room();
        RoomBooking::create([
            'tenant_id' => $this->tenant->id,
            'meeting_room_id' => $room->id,
            'employee_id' => $this->employee->id,
            'date' => now()->toDateString(),
            'start_time' => '09:00',
            'end_time' => '10:00',
            'title' => 'Existing',
            'status' => 'confirmed',
        ]);

        // Act — a request that overlaps 09:30–10:30 on the same room+date.
        $response = $this->actingInTenant()->post('/app/rooms/book', [
            'meeting_room_id' => $room->id,
            'date' => now()->toDateString(),
            'start_time' => '09:30',
            'end_time' => '10:30',
            'title' => 'Conflicting',
        ]);

        // Assert — graceful rejection, no new row.
        $response->assertSessionHasErrors('booking');
        $this->assertDatabaseMissing('room_bookings', ['title' => 'Conflicting']);
        $this->assertSame(1, RoomBooking::where('meeting_room_id', $room->id)->count());
    }

    public function test_non_overlapping_slot_succeeds(): void
    {
        // Arrange — existing booking 09:00–10:00; new one is back-to-back at 10:00.
        $room = $this->room();
        RoomBooking::create([
            'tenant_id' => $this->tenant->id,
            'meeting_room_id' => $room->id,
            'employee_id' => $this->employee->id,
            'date' => now()->toDateString(),
            'start_time' => '09:00',
            'end_time' => '10:00',
            'title' => 'Existing',
            'status' => 'confirmed',
        ]);

        // Act — half-open intervals: [09:00,10:00) and [10:00,11:00) do not overlap.
        $response = $this->actingInTenant()->post('/app/rooms/book', [
            'meeting_room_id' => $room->id,
            'date' => now()->toDateString(),
            'start_time' => '10:00',
            'end_time' => '11:00',
            'title' => 'Back to back',
        ]);

        // Assert
        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('room_bookings', [
            'meeting_room_id' => $room->id,
            'title' => 'Back to back',
            'status' => 'confirmed',
        ]);
    }

    public function test_owner_cancels_own_booking(): void
    {
        // Arrange
        $room = $this->room();
        $booking = RoomBooking::create([
            'tenant_id' => $this->tenant->id,
            'meeting_room_id' => $room->id,
            'employee_id' => $this->employee->id,
            'date' => now()->toDateString(),
            'start_time' => '09:00',
            'end_time' => '10:00',
            'title' => 'Mine',
            'status' => 'confirmed',
        ]);

        // Act
        $response = $this->actingInTenant()->post("/app/rooms/bookings/{$booking->id}/cancel");

        // Assert
        $response->assertRedirect();
        $this->assertSame('cancelled', $booking->fresh()->status);
    }

    // ── Room management ───────────────────────────────────────────

    public function test_privileged_user_creates_a_room(): void
    {
        // Arrange
        $hr = $this->hrActor();

        // Act
        $response = $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->post('/app/rooms', [
                'name' => 'Innovation Lab',
                'location' => 'Level 5',
                'capacity' => 12,
            ]);

        // Assert
        $response->assertRedirect();
        $this->assertDatabaseHas('meeting_rooms', [
            'tenant_id' => $this->tenant->id,
            'name' => 'Innovation Lab',
            'capacity' => 12,
            'active' => true,
        ]);
    }

    public function test_plain_employee_cannot_create_a_room(): void
    {
        // Act
        $response = $this->actingInTenant()->post('/app/rooms', [
            'name' => 'Sneaky Room',
            'capacity' => 4,
        ]);

        // Assert
        $response->assertForbidden();
        $this->assertDatabaseMissing('meeting_rooms', ['name' => 'Sneaky Room']);
    }
}
