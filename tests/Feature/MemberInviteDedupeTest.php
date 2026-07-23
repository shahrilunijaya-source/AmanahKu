<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * AK-DB-03 — inviting someone who is already in the directory must attach a login to
 * that EXISTING employee, never mint a second Employee + User for the same email.
 *
 * Reproduces the 2026-07-23 staging incident: a person was added to the directory
 * (login-less), then invited by email ~16 min later, producing two active employee
 * rows (one login-less, one with a fresh user) for the same person.
 */
class MemberInviteDedupeTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $this->hr = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $this->hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);
    }

    private function actingHr(): self
    {
        $this->actingAs($this->hr)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    public function test_inviting_an_existing_directory_person_reuses_their_row(): void
    {
        // Step 1: the person is added to the directory (login-less), as EmployeeController::store does.
        $directory = Employee::create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Shazwan Shah',
            'email' => 'shazwanshah@example.com',
            'status' => 'active',
            'workload' => 'green',
        ]);
        $this->assertNull($directory->user_id);

        // Step 2: ~16 min later they are invited by the same email via the Members form.
        $this->actingHr()->post('/app/members', [
            'name' => 'Shazwan Shah',
            'email' => 'shazwanshah@example.com',
            'role' => 'employee',
        ])->assertRedirect();

        // Exactly one employee and one user must hold that email — no duplicate.
        $this->assertSame(1, Employee::withoutGlobalScopes()->where('email', 'shazwanshah@example.com')->count());
        $this->assertSame(1, User::where('email', 'shazwanshah@example.com')->count());

        // The invite attached a login to the SAME directory row.
        $directory->refresh();
        $this->assertNotNull($directory->user_id);
        $this->assertSame('shazwanshah@example.com', $directory->user->email);
    }
}
