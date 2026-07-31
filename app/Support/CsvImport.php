<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\UploadedFile;

/**
 * Shared scaffolding for the bulk CSV imports (staff, position bands). Encapsulates the
 * mechanical, identical parts — open + header parse, case-insensitive column access, the
 * shared key normaliser, the row cap, and the result-message assembly — so each importer
 * keeps only its own lookups + per-row logic (AK-CODE-02). The row loop, any transaction,
 * and second-pass linking stay in the caller, because those genuinely differ per domain.
 */
final class CsvImport
{
    /** Hard cap on rows imported in one file (runaway guard). */
    public const ROW_CAP = 1000;

    /** Case-insensitive lookup key: lower-cased + trimmed. Shared by every importer. */
    public static function key(mixed $value): string
    {
        return mb_strtolower(trim((string) $value));
    }

    /**
     * Open an uploaded CSV positioned after the header row.
     *
     * @return array{0: resource|null, 1: array<string,int>, 2: string|null}
     *                                                                       [handle, column-index map, error]. On success error is null and the
     *                                                                       caller owns fclose(); on failure handle is null + error is set.
     */
    public static function open(UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'r');
        if ($handle === false) {
            return [null, [], 'Could not read the uploaded file.'];
        }

        $header = fgetcsv($handle);
        if (! $header) {
            fclose($handle);

            return [null, [], 'The file is empty.'];
        }

        // Header name (lower/trimmed) → column index, so a row cell is read by name.
        $col = array_flip(array_map(fn ($h) => strtolower(trim((string) $h)), $header));

        return [$handle, $col, null];
    }

    /**
     * Read one cell from a CSV row by (case-insensitive) header name, trimmed. Returns ''
     * when the column is absent or empty.
     *
     * @param  array<int, string|null>  $row
     * @param  array<string, int>  $col
     */
    public static function cell(array $row, array $col, string $name): string
    {
        return isset($col[$name]) ? trim((string) ($row[$col[$name]] ?? '')) : '';
    }

    /**
     * Assemble the flash summary: "<created> <noun>." + optional extra + a truncated list
     * of skipped-row errors.
     *
     * @param  array<int, string>  $errors
     */
    public static function summary(int $created, string $noun, array $errors, string $extra = ''): string
    {
        $msg = "$created $noun.";
        if ($extra !== '') {
            $msg .= ' '.$extra;
        }
        if ($errors !== []) {
            $msg .= ' '.count($errors).' row(s) skipped: '.implode(' ', array_slice($errors, 0, 5));
        }

        return $msg;
    }
}
