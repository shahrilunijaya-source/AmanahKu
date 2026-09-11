<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Drops failed jobs that were dispatched by hand from `php artisan tinker`.
 *
 * Prod carries three from launch day (2026-07-31), all manual tests, never app work:
 * - one "Closure (ExecutionClosure.php(41) : eval()'d code:1)", the name a closure
 *   typed into tinker gets;
 * - two bare `Illuminate\Mail\Mailable` with no view, no subject and a recipient
 *   missing its "@". The app only ever queues Mailable subclasses, so the base class
 *   can only come from a hand-typed test, and with no view it can never send.
 *
 * They keep the super-admin "Queued jobs are failing" banner lit forever, and the
 * banner's own advice (`queue:retry all`) would re-run them. Only these two shapes
 * are removed; a real failed job stays visible.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tinkerJobIds = DB::table('failed_jobs')
            ->get(['id', 'payload'])
            ->filter(function (object $job): bool {
                $displayName = (string) data_get(json_decode($job->payload, true), 'displayName', '');

                return str_contains($displayName, "eval()'d code")
                    || $displayName === 'Illuminate\\Mail\\Mailable';
            })
            ->pluck('id');

        DB::table('failed_jobs')->whereIn('id', $tinkerJobIds)->delete();
    }

    /**
     * Nothing to restore: the rows were dead test jobs, and the pre-migrate dump
     * deploy.sh takes holds them if they are ever wanted.
     */
    public function down(): void {}
};
