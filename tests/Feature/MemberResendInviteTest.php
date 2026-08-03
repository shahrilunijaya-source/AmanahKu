<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\MemberInvited;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * HR can send the activation invite again.
 *
 * The invite is queued mail, so a mail outage or a stopped queue worker swallows it
 * with no trace: the member hears nothing and, before this, HR had no way to try
 * again (prod, 2026-08-03 — seven members stranded with no recovery path).
 *
 * The invite carries a one-time password that is only ever stored hashed, so a resend
 * has to mint a new one. That is safe while the account is unactivated and unsafe
 * afterwards, which is why an activated account is refused here rather than silently
 * having its working password rotated away.
 */
class MemberResendInviteTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
    }

    /** @return array{0: User, 1: User, 2: Employee} */
    private function unactivatedMember(): array
    {
        $hr = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);

        $target = User::create(['name' => 'Pat', 'email' => 'pat@example.com', 'password' => Hash::make('never-used')]);
        $target->forceFill(['password_change_required' => true])->save();
        $target->tenants()->attach($this->tenant->id, ['role' => 'manager']);
        $employee = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $target->id,
            'name' => 'Pat', 'status' => 'active', 'workload' => 'green',
        ]);

        return [$hr, $target, $employee];
    }

    private function resend(User $actor, Employee $employee)
    {
        return $this->actingAs($actor)->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/members/{$employee->id}/resend-invite");
    }

    public function test_it_emails_the_member_a_fresh_invite(): void
    {
        Notification::fake();
        [$hr, $target, $employee] = $this->unactivatedMember();

        $this->resend($hr, $employee)->assertRedirect()->assertSessionHas('ok');

        Notification::assertSentTo($target, MemberInvited::class);
        $this->assertDatabaseHas('audit_logs', ['action' => 'Resent invite', 'target' => 'Pat']);
    }

    /** The lost invite must stop working — its password is unrecoverable, so a new one is minted. */
    public function test_it_replaces_the_one_time_password(): void
    {
        Notification::fake();
        [$hr, $target, $employee] = $this->unactivatedMember();

        $this->resend($hr, $employee);

        $fresh = $target->fresh();
        $this->assertFalse(Hash::check('never-used', $fresh->password));
        // Still an unactivated account: the member sets their own password on arrival.
        $this->assertTrue($fresh->password_change_required);
    }

    /** Re-inviting someone who already signed in would rotate a working password away. */
    public function test_it_refuses_an_account_that_has_already_been_activated(): void
    {
        Notification::fake();
        [$hr, $target, $employee] = $this->unactivatedMember();
        $target->forceFill(['password_change_required' => false])->save();

        $this->resend($hr, $employee)->assertRedirect()->assertSessionHas('error');

        Notification::assertNothingSent();
        $this->assertTrue(Hash::check('never-used', $target->fresh()->password));
    }

    public function test_it_tells_hr_to_create_a_login_first_when_there_is_none(): void
    {
        Notification::fake();
        [$hr] = $this->unactivatedMember();
        $loginless = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Sam',
            'status' => 'active', 'workload' => 'green',
        ]);

        $this->resend($hr, $loginless)->assertRedirect()->assertSessionHas('error');

        Notification::assertNothingSent();
    }

    /** Minting a credential is an HR/management act — an ordinary member must not reach it. */
    public function test_an_ordinary_member_cannot_resend_an_invite(): void
    {
        Notification::fake();
        [, $target, $employee] = $this->unactivatedMember();

        $plain = User::create(['name' => 'Rank', 'email' => 'rank@example.com', 'password' => Hash::make('password')]);
        $plain->tenants()->attach($this->tenant->id, ['role' => 'employee']);

        $this->resend($plain, $employee)->assertForbidden();

        Notification::assertNothingSent();
        $this->assertTrue(Hash::check('never-used', $target->fresh()->password));
    }

    /** A tenant's HR must never re-credential the platform super-admin. */
    public function test_it_refuses_a_super_admin_account(): void
    {
        Notification::fake();
        [$hr, $target, $employee] = $this->unactivatedMember();
        $target->forceFill(['is_super_admin' => true])->save();

        $this->resend($hr, $employee)->assertForbidden();

        Notification::assertNothingSent();
    }
}
