<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * An HR-initiated reset emails the member a reset link, on top of the one-time
 * password it shows to HR.
 *
 * Ordering is the whole point of this feature. The password is rotated first, then
 * the mail is attempted, and a mail failure must never propagate: by the time the
 * send runs, the old password is already dead, so losing the flash would lock the
 * member out with HR unable to help.
 *
 * See docs/superpowers/specs/2026-07-28-hr-reset-emails-a-link-design.md.
 */
class HrResetPasswordEmailTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
    }

    /** @return array{0: User, 1: User, 2: Employee} */
    private function targetWithLogin(): array
    {
        $hr = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);

        $target = User::create(['name' => 'Pat', 'email' => 'pat@example.com', 'password' => Hash::make('old-password')]);
        $target->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $employee = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $target->id,
            'name' => 'Pat', 'status' => 'active', 'workload' => 'green',
        ]);

        return [$hr, $target, $employee];
    }

    private function reset(User $hr, Employee $employee)
    {
        return $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/members/{$employee->id}/reset-password");
    }

    public function test_it_emails_the_member_a_reset_link(): void
    {
        Notification::fake();
        [$hr, $target, $employee] = $this->targetWithLogin();

        $this->reset($hr, $employee)->assertRedirect();

        Notification::assertSentTo($target, ResetPassword::class);
    }

    public function test_it_still_reveals_the_one_time_password_when_the_mail_is_sent(): void
    {
        Notification::fake();
        [$hr, $target, $employee] = $this->targetWithLogin();

        $this->reset($hr, $employee)->assertSessionHas('reset_password');

        $flashed = session('reset_password');
        $this->assertTrue(Hash::check($flashed['password'], $target->fresh()->password));
        $this->assertSame('sent', $flashed['mail']);
    }

    /**
     * The lockout guard. By the time the mailer runs the old password is already
     * dead, so a throwing mailer must not cost HR the replacement credential.
     */
    public function test_it_still_reveals_the_one_time_password_when_the_mailer_throws(): void
    {
        [$hr, $target, $employee] = $this->targetWithLogin();

        Password::shouldReceive('broker')->andThrow(new \RuntimeException('SMTP is down'));

        $this->reset($hr, $employee)->assertRedirect()->assertSessionHas('reset_password');

        $flashed = session('reset_password');
        $this->assertSame('failed', $flashed['mail']);
        // The credential HR was handed is the one that actually works.
        $this->assertTrue(Hash::check($flashed['password'], $target->fresh()->password));
        $this->assertFalse(Hash::check('old-password', $target->fresh()->password));
    }

    public function test_it_reports_a_throttled_send_without_pretending_mail_went_out(): void
    {
        Notification::fake();
        [$hr, , $employee] = $this->targetWithLogin();

        // The broker throttles repeats to one per 60 seconds (config/auth.php).
        $this->reset($hr, $employee);
        $this->reset($hr, $employee)->assertSessionHas('reset_password');

        $this->assertSame('throttled', session('reset_password')['mail']);
    }

    public function test_existing_reset_behaviour_is_unchanged(): void
    {
        Notification::fake();
        [$hr, $target, $employee] = $this->targetWithLogin();

        $this->reset($hr, $employee);

        $fresh = $target->fresh();
        $this->assertTrue($fresh->password_change_required);
        $this->assertFalse(Hash::check('old-password', $fresh->password));
        // The plaintext must never reach the audit trail.
        $this->assertDatabaseHas('audit_logs', ['action' => 'Reset password', 'target' => 'Pat']);
        $this->assertDatabaseMissing('audit_logs', ['target' => session('reset_password')['password']]);
    }
}
