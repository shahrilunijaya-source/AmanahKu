<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The migration has already run on an empty failed_jobs table by the time
 * RefreshDatabase finishes, so each test seeds rows and calls up() again.
 */
class ForgetFailedTinkerTestJobsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function failJob(string $displayName): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => $displayName]),
            'exception' => 'Exception',
            'failed_at' => now(),
        ]);
    }

    private function runMigration(): void
    {
        $migration = require base_path('database/migrations/2026_09_25_160000_forget_failed_tinker_test_jobs.php');
        $migration->up();
    }

    public function test_tinker_test_jobs_are_removed_and_real_failures_stay(): void
    {
        $this->failJob("Closure (ExecutionClosure.php(41) : eval()'d code:1)");
        $this->failJob("Closure (ExecutionClosure.php(41) : eval()'d code:3)");
        $this->failJob('Illuminate\\Mail\\Mailable');
        $this->failJob('App\\Notifications\\InviteNotification');
        $this->failJob('App\\Mail\\WeeklyDigestMail');
        $this->failJob('Closure (routes/console.php:12)');

        $this->runMigration();

        $this->assertSame(
            ['App\\Mail\\WeeklyDigestMail', 'App\\Notifications\\InviteNotification', 'Closure (routes/console.php:12)'],
            DB::table('failed_jobs')->pluck('payload')
                ->map(fn (string $payload): string => json_decode($payload, true)['displayName'])
                ->sort()->values()->all(),
        );
    }

    public function test_the_super_admin_banner_clears_once_only_tinker_jobs_failed(): void
    {
        $this->failJob("Closure (ExecutionClosure.php(41) : eval()'d code:1)");
        $this->failJob('Illuminate\\Mail\\Mailable');
        $this->failJob('Illuminate\\Mail\\Mailable');

        $this->runMigration();

        $this->assertSame(0, DB::table('failed_jobs')->count());
    }
}
