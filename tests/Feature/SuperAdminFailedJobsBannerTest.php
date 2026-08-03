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

    /** Mimics a queued job sitting in the table, due $minutesAgo minutes ago. */
    private function recordPendingJob(int $minutesAgo): void
    {
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\Notifications\MemberInvited']),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->subMinutes($minutesAgo)->getTimestamp(),
            'created_at' => now()->subMinutes($minutesAgo)->getTimestamp(),
        ]);
    }

    /**
     * The dead-worker case. A job nobody picks up never fails, so the failed-jobs
     * banner stays silent while every invite piles up unsent — exactly what stranded
     * seven members on prod while the console looked healthy.
     */
    public function test_it_warns_when_queued_jobs_are_waiting_with_no_worker(): void
    {
        $this->recordPendingJob(45);

        $this->actingAs($this->superAdmin())
            ->get(route('superadmin.companies.index'))
            ->assertOk()
            ->assertSee('Queued jobs are not being processed')
            ->assertDontSee('Queued jobs are failing');
    }

    /** A job queued seconds ago is normal, not an outage. */
    public function test_it_ignores_jobs_that_are_only_briefly_pending(): void
    {
        $this->recordPendingJob(1);

        $this->actingAs($this->superAdmin())
            ->get(route('superadmin.companies.index'))
            ->assertOk()
            ->assertDontSee('Queued jobs are not being processed');
    }

    /**
     * The two alarms are independent. Stale failures from an unrelated job must not
     * stand in for, or mask, a stopped worker.
     */
    public function test_a_stalled_queue_is_reported_alongside_unrelated_failures(): void
    {
        $this->recordFailedJob('Closure (eval()\'d code)');
        $this->recordPendingJob(30);

        $this->actingAs($this->superAdmin())
            ->get(route('superadmin.companies.index'))
            ->assertOk()
            ->assertSee('Queued jobs are failing')
            ->assertSee('Queued jobs are not being processed');
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
