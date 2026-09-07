<?php

declare(strict_types=1);

namespace App\Support;

use App\Attendance\HolidayEve;
use App\Models\Employee;
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
     * @return array{moments: list<Moment>, moments_start: int, management: array<string, mixed>|null, awards: array<string, mixed>|null}
     */
    public static function compose(array $moments, ?array $management, ?array $awards, CarbonImmutable $today): array
    {
        return [
            'moments' => $moments,
            // Which one opens today. Every moment is in the page; the pill steps through the rest.
            'moments_start' => $moments === [] ? 0 : $today->dayOfYear % count($moments),
            'management' => $management,
            'awards' => $awards,
        ];
    }

    /**
     * One moment per colleague whose birthday is today. Names come from the same
     * active-staff, month+day match the calendar uses, so both agree.
     *
     * @param  iterable<Employee>  $people
     * @return list<Moment>
     */
    public static function birthdayMoments(iterable $people, CarbonImmutable $today, ?int $selfId = null): array
    {
        $out = [];
        foreach ($people as $person) {
            if ($person->date_of_birth === null || (int) $person->date_of_birth->format('n') !== $today->month
                || (int) $person->date_of_birth->format('j') !== $today->day) {
                continue;
            }
            $name = $person->display_name;
            if ($person->id === $selfId) {
                $out[] = [
                    'kind' => 'birthday',
                    'kicker' => ['en' => 'Today', 'ms' => 'Hari ini'],
                    'title' => ['en' => "Happy birthday, {$name}!", 'ms' => "Selamat hari lahir, {$name}!"],
                    'sub' => ['en' => 'From everyone here. Have a good one.', 'ms' => 'Daripada kami semua. Semoga ceria.'],
                    'cta' => null,
                    'art' => 'cake',
                ];

                continue;
            }
            $out[] = [
                'kind' => 'birthday',
                'kicker' => ['en' => 'Today', 'ms' => 'Hari ini'],
                'title' => ['en' => "It's {$name}'s birthday", 'ms' => "Hari lahir {$name}"],
                'sub' => ['en' => 'Drop them a wish before 5 PM.', 'ms' => 'Ucapkan selamat sebelum 5 petang.'],
                'cta' => ['label' => ['en' => 'Send a wish', 'ms' => 'Hantar ucapan'], 'url' => route('app.screen', 'directory')],
                'art' => 'cake',
            ];
        }

        return $out;
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
