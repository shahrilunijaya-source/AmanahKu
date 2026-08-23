<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\CurrentTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Throwable;

/**
 * One recorded clock attempt. See the migration for why this table exists.
 *
 * Deliberately not tenant-scoped: the only reader is a super-admin who works above
 * every company, and a punch can be refused before an employee profile is resolved.
 */
class AttendanceAttempt extends Model
{
    /** Only created_at is kept: a recorded attempt is never edited. */
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'has_location' => 'boolean',
            'has_photo' => 'boolean',
        ];
    }

    /**
     * Record one attempt, reading the device context off the current request.
     *
     * Never throws. This is a diagnostic write on the success path of a punch: if
     * recording the attempt failed and that failure escaped, it would turn a punch that
     * worked into an error page, which is a worse bug than the one this table exists to
     * find.
     */
    public static function record(
        ?Employee $employee,
        ?string $action,
        string $outcome,
        ?string $message,
        bool $hasLocation,
        ?UploadedFile $photo,
    ): void {
        try {
            static::create([
                'tenant_id' => app(CurrentTenant::class)->id(),
                'user_id' => Auth::id(),
                'employee_id' => $employee?->id,
                'action' => $action,
                'outcome' => $outcome,
                'message' => $message === null ? null : mb_substr($message, 0, 500),
                'has_location' => $hasLocation,
                'has_photo' => $photo !== null,
                // isValid() first, and it is not belt-and-braces. A file PHP refused
                // (over upload_max_filesize) has no temp file behind it, and getSize()
                // does not return false there — it THROWS on the failed stat, which the
                // catch below then swallowed along with the whole row. That silently lost
                // the one case this table was built to explain. Null rather than 0:
                // "no size" and "empty file" are different findings.
                'photo_bytes' => $photo && $photo->isValid() && is_int($size = $photo->getSize()) ? $size : null,
                'content_length' => (int) Request::server('CONTENT_LENGTH') ?: null,
                'user_agent' => mb_substr((string) Request::userAgent(), 0, 512),
                'ip' => Request::ip(),
                'created_at' => now(),
            ]);
        } catch (Throwable) {
            // Swallowed on purpose — see the docblock.
        }
    }

    /**
     * The signed-in user who attempted the punch.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The company the attempt was made in.
     *
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * The employee profile the punch was for, if one was resolved.
     *
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Bytes as a readable size, switching unit so a small body is not printed as "0MB".
     *
     * A punch with no selfie sends under a kilobyte; a punch with one sends megabytes.
     * The whole point of the column is telling those two apart at a glance.
     */
    public static function humanBytes(?int $bytes): string
    {
        return match (true) {
            $bytes === null => '—',
            $bytes < 1024 => $bytes.'B',
            $bytes < 1048576 => round($bytes / 1024).'KB',
            default => round($bytes / 1048576, 2).'MB',
        };
    }

    /**
     * Coarse device class read off the user agent, for the list column.
     *
     * A guess, and labelled as one in the view. It exists so a super-admin can see
     * "every refusal today was a phone" at a glance instead of reading twelve agent
     * strings; the full string is on the row for anyone who needs certainty.
     */
    public function deviceLabel(): string
    {
        $agent = (string) $this->user_agent;

        if ($agent === '') {
            return 'Unknown';
        }

        // iPad before Macintosh: iPadOS Safari claims to be a Mac and is only told apart
        // by the literal 'iPad' token some builds still send.
        return match (true) {
            str_contains($agent, 'iPad') => 'iPad',
            str_contains($agent, 'iPhone') => 'iPhone',
            str_contains($agent, 'Android') => str_contains($agent, 'Mobile') ? 'Android phone' : 'Android tablet',
            str_contains($agent, 'Windows') || str_contains($agent, 'Macintosh') || str_contains($agent, 'X11') => 'Desktop',
            default => 'Unknown',
        };
    }
}
