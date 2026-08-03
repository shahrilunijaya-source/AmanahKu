<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Correcting a staff member's email must move their login with it.
 *
 * employees.email and users.email are separate columns, and the login uses its own as
 * the username. Before this, only the directory row moved: HR fixed a typo, saw
 * "updated", and every later invite and reset still went to the wrong inbox with no
 * warning — the failure that stranded seven staff on prod (2026-08-02), where the
 * addresses had been corrected days before anyone noticed the logins had not.
 */
class MemberLoginEmailSyncTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
    }

    private function hr(): User
    {
        $hr = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);

        return $hr;
    }

    /** @return array{0: User, 1: Employee} */
    private function memberWithLogin(string $email = 'typo@example.com', bool $activated = false): array
    {
        $user = User::create(['name' => 'Pat', 'email' => $email, 'password' => Hash::make('secret')]);
        $user->forceFill([
            'password_change_required' => ! $activated,
            'email_verified_at' => $activated ? now() : null,
        ])->save();
        $user->tenants()->attach($this->tenant->id, ['role' => 'employee']);

        $employee = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'name' => 'Pat', 'email' => $email, 'status' => 'active', 'workload' => 'green',
        ]);

        return [$user, $employee];
    }

    /** @param  array<string, mixed>  $overrides */
    private function edit(User $actor, Employee $employee, array $overrides = [])
    {
        return $this->actingAs($actor)->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/employees/{$employee->id}", $overrides + [
                'name' => $employee->name,
                'status' => 'active',
            ]);
    }

    public function test_correcting_the_email_moves_the_login_too(): void
    {
        Notification::fake();
        $hr = $this->hr();
        [$user, $employee] = $this->memberWithLogin();

        $this->edit($hr, $employee, ['email' => 'pat@example.com'])->assertRedirect();

        $this->assertSame('pat@example.com', $user->fresh()->email);
        $this->assertSame('pat@example.com', $employee->fresh()->email);
        $this->assertDatabaseHas('audit_logs', ['action' => 'Changed login email', 'target' => 'Pat']);
    }

    /** The invite is the proof of address — a second mail nobody can act on helps no one. */
    public function test_an_unactivated_account_is_not_asked_to_verify(): void
    {
        Notification::fake();
        $hr = $this->hr();
        [$user, $employee] = $this->memberWithLogin();

        $this->edit($hr, $employee, ['email' => 'pat@example.com']);

        Notification::assertNothingSentTo($user);
        $this->assertTrue($user->fresh()->password_change_required);
    }

    /** A live account changing address has to prove the new one is theirs. */
    public function test_an_activated_account_must_reverify_the_new_address(): void
    {
        Notification::fake();
        $hr = $this->hr();
        [$user, $employee] = $this->memberWithLogin('old@example.com', activated: true);

        $this->edit($hr, $employee, ['email' => 'new@example.com']);

        $this->assertSame('new@example.com', $user->fresh()->email);
        $this->assertNull($user->fresh()->email_verified_at);
        Notification::assertSentTo($user, VerifyEmail::class);
    }

    /** users.email is globally unique — the collision must be a validation error, not a 500. */
    public function test_it_refuses_an_email_that_belongs_to_another_login(): void
    {
        $hr = $this->hr();
        [$user, $employee] = $this->memberWithLogin();
        User::create(['name' => 'Someone Else', 'email' => 'taken@example.com', 'password' => Hash::make('secret')]);

        $this->edit($hr, $employee, ['email' => 'taken@example.com'])->assertSessionHasErrors('email');

        // Neither column moved — the directory edit rolls back with the login write.
        $this->assertSame('typo@example.com', $user->fresh()->email);
        $this->assertSame('typo@example.com', $employee->fresh()->email);
    }

    /** Blanking the directory email must never blank a username and lock the person out. */
    public function test_clearing_the_directory_email_leaves_the_login_alone(): void
    {
        Notification::fake();
        $hr = $this->hr();
        [$user, $employee] = $this->memberWithLogin();

        $this->edit($hr, $employee, ['email' => '']);

        $this->assertNull($employee->fresh()->email);
        $this->assertSame('typo@example.com', $user->fresh()->email);
    }

    /** A directory row with no login has nothing to carry the change to. */
    public function test_a_record_without_a_login_still_edits_cleanly(): void
    {
        Notification::fake();
        $hr = $this->hr();
        $employee = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Sam',
            'email' => 'sam@example.com', 'status' => 'active', 'workload' => 'green',
        ]);

        $this->edit($hr, $employee, ['email' => 'sam.new@example.com'])->assertRedirect();

        $this->assertSame('sam.new@example.com', $employee->fresh()->email);
    }
}
