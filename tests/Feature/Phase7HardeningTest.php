<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Notifications\MemberInvited;
use App\Notifications\WeeklyHrDigest;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Phase 7 production-hardening checks.
 *  - AK-TEST-03: the invite / digest notifications ride the queue (ShouldQueue), so a
 *    slow SMTP send never blocks the provisioning request.
 *  - AK-CONFIG-02: `php artisan about` warns when a production deploy is still on the
 *    `log` mailer (reset/invite mail silently dropped).
 */
class Phase7HardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_invite_is_a_queued_notification(): void
    {
        Notification::fake();

        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $user = User::create(['name' => 'Invitee', 'email' => 'invitee@example.com', 'password' => Hash::make('x')]);

        $user->notify(new MemberInvited($tenant, 'one-time-pass-123', 'hr'));

        Notification::assertSentTo(
            $user,
            MemberInvited::class,
            fn (MemberInvited $n) => $n instanceof ShouldQueue,
        );
    }

    public function test_weekly_hr_digest_implements_should_queue(): void
    {
        $this->assertInstanceOf(ShouldQueue::class, $this->weeklyDigestStub());
    }

    private function weeklyDigestStub(): WeeklyHrDigest
    {
        // An unsaved tenant + empty summary is enough to assert the queued contract
        // without exercising the mail body (the constructor touches no database).
        return new WeeklyHrDigest(new Tenant, []);
    }

    public function test_about_warns_when_production_still_uses_the_log_mailer(): void
    {
        Config::set('mail.default', 'log');
        $this->app->detectEnvironment(fn () => 'production');

        Artisan::call('about', ['--only' => 'amanahku']);

        $this->assertStringContainsString('WARNING', Artisan::output());
    }

    public function test_about_is_ok_when_mailer_is_not_log_in_production(): void
    {
        Config::set('mail.default', 'smtp');
        $this->app->detectEnvironment(fn () => 'production');

        Artisan::call('about', ['--only' => 'amanahku']);

        $this->assertStringNotContainsString('WARNING', Artisan::output());
    }
}
