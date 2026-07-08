<?php

use App\Http\Middleware\ApiTenant;
use App\Http\Middleware\BlockRegistrationWhenDisabled;
use App\Http\Middleware\EnforceTwoFactor;
use App\Http\Middleware\EnsureCompanyIsActive;
use App\Http\Middleware\EnsureModuleEnabled;
use App\Http\Middleware\EnsureNotArchived;
use App\Http\Middleware\EnsureProfileComplete;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\EnsureSystemLaunched;
use App\Http\Middleware\ForcePasswordChange;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule): void {
        // withoutOverlapping guards against a slow run colliding with the next tick;
        // onFailure leaves a trace if the whole command aborts (per-tenant errors are
        // already isolated + logged inside each command — see AK-REL-04).
        $onFailure = fn (string $cmd) => fn () => logger()->error("Scheduled command failed: {$cmd}");

        // Year-boundary carry-forward + expiry MUST run before January's accrual:
        // if accrual lands first, carry-forward then caps/expires balances that
        // already include the new year's first month — silently destroying it.
        $schedule->command('leave:carry-forward')->yearlyOn(1, 1, '01:00')
            ->withoutOverlapping()->onFailure($onFailure('leave:carry-forward'));
        // Monthly leave accrual: 1st of each month at 02:00 (after carry-forward
        // on Jan 1). Idempotent within the month, so a missed/duplicate run is safe.
        $schedule->command('leave:accrue')->monthlyOn(1, '02:00')
            ->withoutOverlapping()->onFailure($onFailure('leave:accrue'));
        // Weekly HR digest: Monday 08:00. Queued notification, so it returns fast
        // and the queue worker delivers the mail.
        $schedule->command('digest:weekly')->weeklyOn(1, '08:00')
            ->withoutOverlapping()->onFailure($onFailure('digest:weekly'));
        // Timesheet reminder: Friday 17:00. Bell-notifies staff who haven't
        // fully filled the current week. Idempotent, so a retry is safe.
        $schedule->command('timesheet:remind')->fridays()->at('17:00')
            ->withoutOverlapping()->onFailure($onFailure('timesheet:remind'));
        // Auto-archive departed staff: daily 00:30. Archives anyone whose acknowledged
        // resignation last working day has passed — runs the full detach cascade and closes
        // the resignation. Idempotent, so a missed/duplicate run is safe.
        $schedule->command('staff:archive-departed')->dailyAt('00:30')
            ->withoutOverlapping()->onFailure($onFailure('staff:archive-departed'));
    })
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind a TLS-terminating proxy (Nginx/ALB) the app only sees HTTP unless it
        // trusts the proxy's X-Forwarded-* headers. Without this, $request->secure() is
        // false for genuine HTTPS, so HSTS is never sent and cookie/scheme decisions
        // misfire (AK-SEC-08). Restrict `at:` to your load-balancer CIDRs if known.
        $middleware->trustProxies(at: '*');

        $middleware->alias([
            'tenant' => ResolveTenant::class,
            'company.active' => EnsureCompanyIsActive::class,
            'not.archived' => EnsureNotArchived::class,
            'super.admin' => EnsureSuperAdmin::class,
            'api.tenant' => ApiTenant::class,
            'module.enabled' => EnsureModuleEnabled::class,
            'system.launched' => EnsureSystemLaunched::class,
            'profile.complete' => EnsureProfileComplete::class,
        ]);
        $middleware->web(append: [
            SetLocale::class,
            SecurityHeaders::class,
            ForcePasswordChange::class,
            EnforceTwoFactor::class,
            BlockRegistrationWhenDisabled::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Render JSON for API routes and any request that asks for it (fetch/XHR with
        // Accept: application/json), so in-app JSON endpoints get proper 4xx payloads.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
