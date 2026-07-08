<?php

declare(strict_types=1);

namespace App\Services\Payroll\BankFile;

/**
 * Registry of available bank-file formats. The generic CSV is the default + fallback.
 */
class BankFileRegistry
{
    /** @return array<int, BankFileFormat> */
    public static function all(): array
    {
        return [
            new GenericCsvFormat(),
            new DuitNowBatchFormat(),
            new Maybank2uBizFormat(),
        ];
    }

    /** Resolve a format by key, falling back to the generic CSV for an unknown/empty key. */
    public static function find(?string $key): BankFileFormat
    {
        foreach (self::all() as $format) {
            if ($format->key() === $key) {
                return $format;
            }
        }

        return new GenericCsvFormat();
    }

    /** @return array<string, string> key => label, for the UI picker. */
    public static function options(): array
    {
        $options = [];
        foreach (self::all() as $format) {
            $options[$format->key()] = $format->label();
        }

        return $options;
    }
}
