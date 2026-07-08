<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Scores working-style answers into one of four animal archetypes by frequency.
 * Ported from the Hiring platform.
 */
class ArchetypeScorer
{
    /** Fixed precedence for tie-breaks. */
    public const ORDER = ['rabbit', 'tortoise', 'fox', 'sloth'];

    /**
     * @param  array<int,string>  $animals  one animal key per answered working-style question
     * @return array{archetype: ?string, totals: array<string,int>}
     */
    public static function score(array $animals): array
    {
        $totals = array_fill_keys(self::ORDER, 0);
        foreach ($animals as $animal) {
            if (array_key_exists($animal, $totals)) {
                $totals[$animal]++;
            }
        }

        $archetype = null;
        $best = 0;
        foreach (self::ORDER as $animal) {
            if ($totals[$animal] > $best) {
                $best = $totals[$animal];
                $archetype = $animal;
            }
        }

        return ['archetype' => $archetype, 'totals' => $totals];
    }
}
