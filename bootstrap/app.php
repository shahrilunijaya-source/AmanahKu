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
use App\Models\AttendanceAttempt;
use App\Models\ErrorEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Sentry\Laravel\Integration;
use Symfony\Component\HttpFoundation\Response;

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
        // Board hygiene: archive Done cards a full day after they were marked done.
        // Hourly (not daily) so "a day" means close to 24h rather than up to 48h on
        // a fixed daily tick. Idempotent: archived_at is only ever set once.
        $schedule->command('work:archive-done')->hourly()
            ->withoutOverlapping()->onFailure($onFailure('work:archive-done'));
        // Clock nudges: every 5 minutes across the working day. The cadence has to be
        // this short because one of the four nudges fires 5 minutes BEFORE the shift
        // boundary — a 15-minute tick would miss that window. Each bell carries its own
        // per-day dedupe key, so the short cadence still costs at most one notification
        // per staffer per type per day regardless of how many ticks fire.
        $schedule->command('attendance:remind')->everyFiveMinutes()->between('6:00', '22:00')
            ->withoutOverlapping()->onFailure($onFailure('attendance:remind'));
        // TOT reminders: 14 days out when the topic is blank, 7 days out for the presenter,
        // 1 day out for everybody. Every send is deduped, so a retry is harmless.
        $schedule->command('tot:remind')->dailyAt('08:00')
            ->withoutOverlapping()->onFailure($onFailure('tot:remind'));
        // Close punches nobody clocked out of, stamped at the shift end. Last thing at
        // night so the whole working day has had its chance to clock out honestly, and
        // late enough that an overnight shift started this evening is still inside its
        // own hours and gets left alone until tomorrow's run.
        $schedule->command('attendance:auto-clock-out')->dailyAt('23:59')
            ->withoutOverlapping()->onFailure($onFailure('attendance:auto-clock-out'));
        // Captured faults are a debugging aid, not a record to keep. Without this the
        // table only grows, and one exception inside a loop can fill it in a day.
        $schedule->call(fn () => ErrorEvent::where('created_at', '<', now()->subDays(30))->delete())
            ->dailyAt('03:00')->name('error-events:prune')->withoutOverlapping();
        // Same reasoning for recorded punch attempts, and the same window: two rows per
        // employee per day is small, but it is a diagnostic trail, not a record to keep.
        // The attendance record itself is the lasting one.
        $schedule->call(fn () => AttendanceAttempt::where('created_at', '<', now()->subDays(30))->delete())
            ->dailyAt('03:05')->name('attendance-attempts:prune')->withoutOverlapping();
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

        // Every reportable exception is written to the app's own database, because
        // production offers no shell, no database client and no log file to read one
        // from. Returning nothing (not false) keeps Laravel's normal logging as well.
        // 404s, CSRF expiries and validation failures never reach here: Laravel skips
        // reportable callbacks for anything it does not report.
        $exceptions->report(function (Throwable $e): void {
            ErrorEvent::capture($e);
        });

        // Sentry rides alongside the table above rather than replacing it. It reaches the
        // same reportable exceptions and no more — it hooks this same chain, so the punches
        // refused at 413/419/429 stay invisible to it and the respond() hook below is still
        // what catches those. What Sentry adds is the half no server can see: the browser
        // SDK in resources/js/sentry.js reports faults that never became a request.
        //
        // A no-op until SENTRY_LARAVEL_DSN is set, so this is safe on a host that has no DSN.
        Integration::handles($exceptions);

        // The reference is only useful if the person who hit the fault can read it out
        // loud. Browsers get it from errors/500.blade.php; the header and the JSON key
        // carry it to fetch callers and to the API.
        $exceptions->respond(function (Response $response, Throwable $e, Request $request): Response {
            // Punches refused before they ever reach the controller: a body over PHP's
            // post_max_size (413), a stale token (419), the route's own rate limit (429).
            // None of these are reportable, so report() above never fires for them and the
            // punch failed leaving no trace at all. stopIgnoring() cannot reach them —
            // it drops exact class names, and PostTooLargeException still matches the
            // HttpException entry that stays in the list.
            //
            // Path, not routeIs(): ValidatePostSize throws before routing resolves.
            // ValidationException is excluded because the controller already records those
            // in attendance_attempts, with the employee and payload attached.
            if ($request->is('app/attendance/clock')
                && ! $e instanceof ValidationException
                && ErrorEvent::currentReference() === null) {
                ErrorEvent::capture($e);
            }

            $reference = ErrorEvent::currentReference();

            if ($reference === null) {
                return $response;
            }

            $response->headers->set('X-Error-Reference', $reference);

            if ($response instanceof JsonResponse && $response->getStatusCode() >= 500) {
                $response->setData($response->getData(true) + ['reference' => $reference]);
            }

            return $response;
        });
    })->create();
