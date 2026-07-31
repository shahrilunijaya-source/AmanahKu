<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The provisioning console is the only surface that tells a super-admin queued work
 * is failing.
 *
 * It cannot be an email: the failure being reported is usually mail itself, so an
 * email alert is silent exactly when it is needed. It cannot be the in-app bell
 * either: AppNotification uses BelongsToTenant, which fails closed and throws when a
 * row is created with no active tenant, and a super-admin sits above every tenant.
 *
 * See docs/superpowers/specs/2026-07-28-staging-mail-delivery-design.md (I-024).
 */
class SuperAdminFailedJobsBannerTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        $u = User::create(['name' => 'Platform', 'email' => 'super@example.com', 'password' => Hash::make('password')]);
        $u->forceFill(['is_super_admin' => true])->save();

        return $u;
    }

    /** Mimics what the queue writes when a queued notification exhausts its retries. */
    private function recordFailedJob(string $displayName = 'App\Notifications\MemberInvited'): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => $displayName]),
            'exception' => 'Symfony\Component\Mailer\Exception\InvalidArgumentException: The "tls" scheme is not supported.',
            'failed_at' => now(),
        ]);
    }

    public function test_it_warns_the_super_admin_when_a_queued_job_has_failed(): void
    {
        $this->recordFailedJob();

        $this->actingAs($this->superAdmin())
            ->get(route('superadmin.companies.index'))
            ->assertOk()
            ->assertSee('Queued jobs are failing')
            ->assertSee('App\Notifications\MemberInvited');
    }

    public function test_it_stays_quiet_when_no_jobs_have_failed(): void
    {
        $this->actingAs($this->superAdmin())
            ->get(route('superadmin.companies.index'))
            ->assertOk()
            ->assertDontSee('Queued jobs are failing');
    }

    public function test_it_counts_every_failed_job_not_just_the_latest(): void
    {
        $this->recordFailedJob();
        $this->recordFailedJob();
        $this->recordFailedJob('App\Notifications\WeeklyHrDigest');

        $this->actingAs($this->superAdmin())
            ->get(route('superadmin.companies.index'))
            ->assertOk()
            ->assertSee('3 queued jobs have failed');
    }
}
