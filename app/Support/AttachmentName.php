<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeInterface;
use Illuminate\Support\Str;

/**
 * Builds the download-time filename for leave attachments and claim receipts. Files are
 * stored on disk under a random hash (see LeaveController::attachment(), ClaimController::
 * receipt()) so a medical certificate or receipt filename never leaks an employee name into
 * directory listings or backups. This class only names the browser's saved copy.
 *
 * Str::slug() strips apostrophes and transliterates non-ASCII, which matters beyond tidiness:
 * an apostrophe (common in Malaysian names like Nur'ain) in a Content-Disposition filename is
 * exactly what Hostinger's ModSecurity WAF blocks on staging.
 */
class AttachmentName
{
    /**
     * @param  string|null  $employeeName  display name; falls back to "staff" when blank
     * @param  string  $kind  e.g. "leave" or "claim"
     * @param  int  $id  the request/claim id
     * @param  string  $storedPath  the on-disk path; its extension is used, never the
     *                              user-supplied original filename
     * @param  DateTimeInterface|null  $date  the request's meaningful date, not the upload time
     */
    public static function build(?string $employeeName, string $kind, int $id, string $storedPath, ?DateTimeInterface $date): string
    {
        $slug = Str::slug($employeeName ?? '') ?: 'staff';
        $extension = pathinfo($storedPath, PATHINFO_EXTENSION);

        $name = "{$slug}-{$kind}-{$id}";
        if ($date !== null) {
            $name .= '-'.$date->format('Y-m-d');
        }

        return $extension === '' ? $name : "{$name}.{$extension}";
    }
}
