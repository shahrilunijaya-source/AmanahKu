<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The one way a person's name is stored: every word starts with a capital. A word typed
 * all in capitals or all in lower case is fixed ("MARYAM" and "maryam" both become
 * "Maryam"), but a word the person typed in mixed case is left alone ("DzulHazly"),
 * since that is how they spell their own name. Extra spaces are squeezed out.
 */
class PersonName
{
    public static function format(string $name): string
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));

        return (string) preg_replace_callback('/[\p{L}\'’]+/u', function (array $m) {
            $word = $m[0];
            $mixed = mb_strtoupper($word) !== $word && mb_strtolower($word) !== $word;
            $word = $mixed ? $word : mb_strtolower($word);

            return mb_strtoupper(mb_substr($word, 0, 1)).mb_substr($word, 1);
        }, $name);
    }
}
