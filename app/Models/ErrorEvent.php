<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Throwable;

/**
 * One captured unhandled exception.
 *
 * Deliberately not tenant-scoped: a fault can happen before a tenant is resolved, or
 * outside any tenant at all, and the only reader is a super-admin who works above
 * every company.
 */
class ErrorEvent extends Model
{
    /** Only created_at is kept: a captured fault is never edited. */
    public const UPDATED_AT = null;

    protected $guarded = [];

    /**
     * Reference of the fault captured during this request, for the error page to print.
     */
    private static ?string $currentReference = null;

    /**
     * Capture an exception and return its user-facing reference.
     *
     * Returns null when nothing was stored. Storage failure is swallowed on purpose:
     * the most common production fault is the database being unreachable, and an
     * exception thrown from inside the exception handler replaces a readable error
     * page with a blank one.
     */
    public static function capture(Throwable $e): ?string
    {
        try {
            $reference = strtoupper(bin2hex(random_bytes(4)));

            static::create([
                'reference' => $reference,
                'fingerprint' => hash('sha256', $e::class.'|'.$e->getFile().'|'.$e->getLine()),
                'exception_class' => $e::class,
                'message' => mb_substr($e->getMessage(), 0, 2000),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => self::traceFrames($e),
                'url' => mb_substr(Request::fullUrl(), 0, 255),
                'method' => Request::method(),
                'ip' => Request::ip(),
                'user_id' => Auth::id(),
                'tenant_id' => app(CurrentTenant::class)->id(),
                'created_at' => now(),
            ]);

            return self::$currentReference = $reference;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The signed-in user at the time of the fault, if there was one.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The company whose workspace the fault happened in, if one was resolved.
     *
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Reference captured during this request, if any.
     */
    public static function currentReference(): ?string
    {
        return self::$currentReference;
    }

    /**
     * Forget the captured reference. Test seam; each request starts a fresh process.
     */
    public static function forgetCurrentReference(): void
    {
        self::$currentReference = null;
    }

    /**
     * Render the stack as file, line and callable only.
     *
     * getTraceAsString() also prints call arguments, truncated to fifteen characters.
     * On a sign-in path those arguments are the submitted password, so the shortcut
     * would write credential fragments into a table that HR staff faults land in.
     * The frames alone locate the fault just as well.
     */
    private static function traceFrames(Throwable $e): string
    {
        $frames = [];

        foreach (array_slice($e->getTrace(), 0, 40) as $i => $frame) {
            $call = ($frame['class'] ?? '').($frame['type'] ?? '').$frame['function'];
            $where = isset($frame['file'])
                ? $frame['file'].':'.($frame['line'] ?? '?')
                : '[internal function]';

            $frames[] = "#{$i} {$where} {$call}()";
        }

        return implode("\n", $frames);
    }
}
