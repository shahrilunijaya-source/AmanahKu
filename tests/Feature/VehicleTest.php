<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleBooking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Feature coverage for the Vehicle / fleet booking module.
 * Harness (setUp / actingInTenant / hrActor) copied from RoomTest.
 */
class VehicleTest extends TestCase
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

    private function vehicle(string $name = 'Toyota Hilux'): Vehicle
    {
        return Vehicle::create([
            'tenant_id' => $this->tenant->id,
            'name' => $name,
            'registration_no' => 'WXY 1234',
            'type' => 'truck',
            'seats' => 5,
            'is_active' => true,
        ]);
    }

    // ── Booking ───────────────────────────────────────────────────

    public function test_employee_books_an_available_window(): void
    {
        // Arrange
        $vehicle = $this->vehicle();

        // Act
        $response = $this->actingInTenant()->post('/app/vehicles/book', [
            'vehicle_id' => $vehicle->id,
            'starts_at' => '2026-07-01 09:00',
            'ends_at' => '2026-07-01 12:00',
            'purpose' => 'Site inspection',
            'destination' => 'Klang depot',
        ]);

        // Assert
        $response->assertRedirect();
        $this->assertDatabaseHas('vehicle_bookings', [
            'tenant_id' => $this->tenant->id,
            'vehicle_id' => $vehicle->id,
            'employee_id' => $this->employee->id,
            'purpose' => 'Site inspection',
            'status' => 'confirmed',
        ]);
    }

    public function test_overlapping_booking_for_same_vehicle_is_rejected(): void
    {
        // Arrange — an existing confirmed booking 09:00–12:00.
        $vehicle = $this->vehicle();
        VehicleBooking::create([
            'tenant_id' => $this->tenant->id,
            'vehicle_id' => $vehicle->id,
            'employee_id' => $this->employee->id,
            'starts_at' => '2026-07-01 09:00',
            'ends_at' => '2026-07-01 12:00',
            'purpose' => 'Existing',
            'status' => 'confirmed',
        ]);

        // Act — a request that overlaps 10:00–13:00 on the same vehicle.
        $response = $this->actingInTenant()->post('/app/vehicles/book', [
            'vehicle_id' => $vehicle->id,
            'starts_at' => '2026-07-01 10:00',
            'ends_at' => '2026-07-01 13:00',
            'purpose' => 'Conflicting',
        ]);

        // Assert — graceful rejection, no new row.
        $response->assertSessionHasErrors('booking');
        $this->assertDatabaseMissing('vehicle_bookings', ['purpose' => 'Conflicting']);
        $this->assertSame(1, VehicleBooking::where('vehicle_id', $vehicle->id)->count());
    }

    public function test_non_overlapping_window_succeeds(): void
    {
        // Arrange — existing booking 09:00–12:00; new one is back-to-back at 12:00.
        $vehicle = $this->vehicle();
        VehicleBooking::create([
            'tenant_id' => $this->tenant->id,
            'vehicle_id' => $vehicle->id,
            'employee_id' => $this->employee->id,
            'starts_at' => '2026-07-01 09:00',
            'ends_at' => '2026-07-01 12:00',
            'purpose' => 'Existing',
            'status' => 'confirmed',
        ]);

        // Act — half-open intervals: [09:00,12:00) and [12:00,14:00) do not overlap.
        $response = $this->actingInTenant()->post('/app/vehicles/book', [
            'vehicle_id' => $vehicle->id,
            'starts_at' => '2026-07-01 12:00',
            'ends_at' => '2026-07-01 14:00',
            'purpose' => 'Back to back',
        ]);

        // Assert
        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('vehicle_bookings', [
            'vehicle_id' => $vehicle->id,
            'purpose' => 'Back to back',
            'status' => 'confirmed',
        ]);
    }

    public function test_owner_cancels_own_booking(): void
    {
        // Arrange
        $vehicle = $this->vehicle();
        $booking = VehicleBooking::create([
            'tenant_id' => $this->tenant->id,
            'vehicle_id' => $vehicle->id,
            'employee_id' => $this->employee->id,
            'starts_at' => '2026-07-01 09:00',
            'ends_at' => '2026-07-01 12:00',
            'purpose' => 'Mine',
            'status' => 'confirmed',
        ]);

        // Act
        $response = $this->actingInTenant()->post("/app/vehicles/bookings/{$booking->id}/cancel");

        // Assert
        $response->assertRedirect();
        $this->assertSame('cancelled', $booking->fresh()->status);
    }

    public function test_cancelling_frees_the_slot_for_a_previously_conflicting_booking(): void
    {
        // Arrange — a confirmed booking 09:00–12:00, then cancel it.
        $vehicle = $this->vehicle();
        $booking = VehicleBooking::create([
            'tenant_id' => $this->tenant->id,
            'vehicle_id' => $vehicle->id,
            'employee_id' => $this->employee->id,
            'starts_at' => '2026-07-01 09:00',
            'ends_at' => '2026-07-01 12:00',
            'purpose' => 'Existing',
            'status' => 'confirmed',
        ]);
        $this->actingInTenant()->post("/app/vehicles/bookings/{$booking->id}/cancel");

        // Act — the previously-conflicting window 10:00–13:00 should now succeed,
        // because cancelled bookings are excluded from the conflict check.
        $response = $this->actingInTenant()->post('/app/vehicles/book', [
            'vehicle_id' => $vehicle->id,
            'starts_at' => '2026-07-01 10:00',
            'ends_at' => '2026-07-01 13:00',
            'purpose' => 'Now free',
        ]);

        // Assert
        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertDatabaseHas('vehicle_bookings', [
            'vehicle_id' => $vehicle->id,
            'purpose' => 'Now free',
            'status' => 'confirmed',
        ]);
    }

    // ── Fleet management ──────────────────────────────────────────

    public function test_privileged_user_creates_a_vehicle(): void
    {
        // Arrange
        $hr = $this->hrActor();

        // Act
        $response = $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->post('/app/vehicles', [
                'name' => 'Toyota Vios',
                'registration_no' => 'WMA 8821',
                'type' => 'car',
                'seats' => 5,
            ]);

        // Assert
        $response->assertRedirect();
        $this->assertDatabaseHas('vehicles', [
            'tenant_id' => $this->tenant->id,
            'name' => 'Toyota Vios',
            'registration_no' => 'WMA 8821',
            'type' => 'car',
            'is_active' => true,
        ]);
    }

    public function test_plain_employee_cannot_create_a_vehicle(): void
    {
        // Act
        $response = $this->actingInTenant()->post('/app/vehicles', [
            'name' => 'Sneaky Van',
            'registration_no' => 'XXX 0000',
            'type' => 'van',
        ]);

        // Assert
        $response->assertForbidden();
        $this->assertDatabaseMissing('vehicles', ['name' => 'Sneaky Van']);
    }
}
