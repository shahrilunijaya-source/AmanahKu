<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Request-scoped reason + source for the audit log. Set by a controller before
 * a write it wants attributed (a "reason" field, an MCP write), read by
 * AuditLog::change()/record() when they write the row, reset after. Static
 * because it lives for exactly one request/console invocation, same lifetime
 * as CurrentTenant's underlying request.
 */
final class AuditContext
{
    private static ?string $reason = null;

    private static ?string $source = null;

    public static function reason(?string $reason = null): ?string
    {
        if (func_num_args() > 0) {
            self::$reason = $reason;
        }

        return self::$reason;
    }

    public static function source(?string $source = null): string
    {
        if (func_num_args() > 0) {
            self::$source = $source;
        }

        if (self::$source !== null) {
            return self::$source;
        }

        // app()->runningInConsole() is PHP_SAPI === 'cli' — true for the whole PHPUnit
        // process, including an HTTP test's $this->patchJson() calls, since those still
        // run under a `php artisan test` CLI process. request()->route() is the more
        // reliable signal: it is only ever set once the HTTP kernel has matched a route,
        // which happens for a real request AND for an HTTP feature test, but never for a
        // console command, a queued job, or a seeder.
        if (request()->route() !== null) {
            return request()->is('api/*') ? 'api' : 'ui';
        }

        return app()->runningInConsole() ? 'job' : 'ui';
    }

    public static function reset(): void
    {
        self::$reason = null;
        self::$source = null;
    }
}
