<?php

declare(strict_types=1);

namespace App\Support;

use App\Attendance\HolidayEve;
use Carbon\CarbonImmutable;

/**
 * The full-width bands above the dashboard grid (CR-32). Three slots in a fixed
 * order, each rendered only when it has something to say, so on an ordinary day
 * the dashboard opens exactly as it did before any of them existed:
 *
 *   moments     one at a time — birthday, holiday eve, big deal, wrapped, bell.
 *               Several active → rotate by day, not by timer (nothing slides
 *               away while you read it).
 *   management  director / HR / senior manager only (CR-17 fills it).
 *   awards      1st working day to the 7th (CR-14 fills it).
 *
 * Bands are not widgets: they are not in the picker, not draggable and not
 * hideable. "Keep it plain" strips their ornament in the view, not here.
 *
 * @phpstan-type Moment array{kind: string, kicker: array{en: string, ms: string}, title: array{en: string, ms: string}, sub: array{en: string, ms: string}, cta: array{label: array{en: string, ms: string}, url: string}|null, art: string|null}
 */
final class DashboardBands
{
    public const SLOTS = ['moments', 'management', 'awards'];

    /**
     * @param  list<Moment>  $moments
     * @return array{moments: Moment|null, moments_count: int, management: array<string, mixed>|null, awards: array<string, mixed>|null}
     */
    public static function compose(array $moments, ?array $management, ?array $awards, CarbonImmutable $today): array
    {
        return [
            'moments' => $moments === [] ? null : $moments[$today->dayOfYear % count($moments)],
            'moments_count' => count($moments),
            'management' => $management,
            'awards' => $awards,
        ];
    }

    /**
     * The holiday-eve moment for the day, or null when the day is not an eve.
     * Same resolver and copy as the clock-out greeting (CR-20).
     *
     * @return Moment|null
     */
    public static function holidayEveMoment(HolidayEve $eve, CarbonImmutable $today): ?array
    {
        $found = $eve->forDay($today);
        if ($found === null) {
            return null;
        }
        $payload = $eve->payload($found['holiday'], $found['next_working_day']);
        $tomorrow = $found['holiday']->date->isSameDay($today->addDay());
        $back = $found['next_working_day'];

        return [
            'kind' => 'holiday-eve',
            'kicker' => ['en' => 'Holiday eve', 'ms' => 'Malam cuti'],
            'title' => [
                'en' => $payload['name'].($tomorrow ? ' tomorrow' : ' on '.$found['holiday']->date->format('D j M')),
                'ms' => $payload['name'].($tomorrow ? ' esok' : ' pada '.$found['holiday']->date->format('D j M')),
            ],
            'sub' => [
                'en' => $payload['greeting_en'].' See you '.$back->format('D j M').'.',
                'ms' => $payload['greeting_ms'].' Jumpa '.$back->format('D j M').'.',
            ],
            'cta' => null,
            'art' => 'stamp',
        ];
    }
}
