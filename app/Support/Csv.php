<?php

declare(strict_types=1);

namespace App\Support;

/**
 * CSV export helpers. Neutralises formula injection (CWE-1236): a spreadsheet
 * treats any cell beginning with =, +, -, @, tab or CR as a formula/DDE payload,
 * so a value like `=cmd|'/c calc'!A1` (settable as a staff name/email) would execute
 * when another user opens the exported file. Prefixing a single quote forces the
 * cell to be read as text.
 */
final class Csv
{
    /** Neutralise a single cell value against formula injection. */
    public static function safe(int|float|string|null $value): string
    {
        $string = (string) $value;

        if ($string === '') {
            return $string;
        }

        return preg_match('/^[=+\-@\t\r]/', $string) === 1 ? "'".$string : $string;
    }

    /**
     * Neutralise every cell in a row.
     *
     * @param  array<int, int|float|string|null>  $row
     * @return array<int, string>
     */
    public static function safeRow(array $row): array
    {
        return array_map(self::safe(...), $row);
    }
}
