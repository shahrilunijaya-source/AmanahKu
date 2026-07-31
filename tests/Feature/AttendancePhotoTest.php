<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Clock selfies (with GPS + timestamp) are sensitive. They live on the private disk and
 * are served only through the auth-gated attendance.photo action — never a public URL
 * (AK-SEC-05). This locks the access matrix: owner yes, guest no, foreign tenant no,
 * unrelated employee no.
 */
class AttendancePhotoTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $ownerUser;

    private AttendanceRecord $record;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->tenant = Tenant::create(['slug' => 'alpha', 'name' => 'Alpha', 'initials' => 'AL']);

        $this->ownerUser = User::create(['name' => 'Owner', 'email' => 'owner@example.com', 'password' => Hash::make('password')]);
        $this->ownerUser->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $ownerEmp = Employee::create(['tenant_id' => $this->tenant->id, 'user_id' => $this->ownerUser->id, 'name' => 'Owner', 'status' => 'active', 'workload' => 'green']);

        Storage::disk('local')->put('attendance-photos/selfie.jpg', 'binary-image-bytes');
        $this->record = AttendanceRecord::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $ownerEmp->id,
            'date' => '2026-07-01', 'status' => 'on_time', 'photo_path' => 'attendance-photos/selfie.jpg',
        ]);
    }

    private function photoUri(string $slot = 'in'): string
    {
        return "/app/attendance/photos/{$this->record->id}/{$slot}";
    }

    public function test_owner_can_view_their_own_clock_selfie(): void
    {
        $this->actingAs($this->ownerUser)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get($this->photoUri('in'))
            ->assertOk();
    }

    public function test_guest_cannot_fetch_a_photo(): void
    {
        // No public URL exists; the route is behind auth — a guest is bounced to login.
        $this->get($this->photoUri('in'))->assertRedirect('/login');
    }

    public function test_unrelated_employee_in_same_tenant_is_denied(): void
    {
        $other = User::create(['name' => 'Nosy', 'email' => 'nosy@example.com', 'password' => Hash::make('password')]);
        $other->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        Employee::create(['tenant_id' => $this->tenant->id, 'user_id' => $other->id, 'name' => 'Nosy', 'status' => 'active', 'workload' => 'green']);

        $this->actingAs($other)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get($this->photoUri('in'))
            ->assertForbidden();
    }

    public function test_foreign_tenant_admin_cannot_fetch_the_photo(): void
    {
        $tenantB = Tenant::create(['slug' => 'bravo', 'name' => 'Bravo', 'initials' => 'BR']);
        $attacker = User::create(['name' => 'Attacker', 'email' => 'attacker@example.com', 'password' => Hash::make('password')]);
        $attacker->tenants()->attach($tenantB->id, ['role' => 'management']);
        Employee::create(['tenant_id' => $tenantB->id, 'user_id' => $attacker->id, 'name' => 'Attacker', 'status' => 'active', 'workload' => 'green']);

        $status = $this->actingAs($attacker)
            ->withSession(['current_tenant' => $tenantB->id])
            ->get($this->photoUri('in'))
            ->status();

        $this->assertContains($status, [403, 404], "Expected cross-tenant denial, got HTTP $status.");
    }

    public function test_missing_slot_photo_is_not_found(): void
    {
        // The record has no clock-out photo — requesting it must 404, not error.
        $this->actingAs($this->ownerUser)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get($this->photoUri('out'))
            ->assertNotFound();
    }
}
