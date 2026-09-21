<?php

declare(strict_types=1);

namespace App\Services\Payroll\Statutory;

final class StatutoryFileRegistry
{
    /** @return array<string, StatutoryFile> */
    public static function all(): array
    {
        $files = [new KwspFormA, new PerkesoBorang8A, new LhdnCp39, new HrdCorpLevyFile];

        return array_combine(array_map(fn (StatutoryFile $f) => $f->key(), $files), $files);
    }

    public static function find(?string $key): ?StatutoryFile
    {
        return self::all()[$key ?? ''] ?? null;
    }
}
