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
 * @phpstan-type Moment array{kind: string, kicker: array{en: string, ms: string}, title: array{en: string, ms: string}, sub: array{en: string, ms: string}, cta: array{label: array{en: string, ms: string}, url: string}|null, art: string|null, date?: string, employee?: array<string, mixed>}
 */
final class DashboardBands
{
    public const SLOTS = ['moments', 'management', 'awards'];

    /**
     * @param  list<Moment>  $moments
     * @param  list<array{name: string, date: string}>  $upcoming
     * @return array{moments: list<Moment>, moments_start: int, management: array<string, mixed>|null, awards: array<string, mixed>|null, upcoming: list<array{name: string, date: string}>}
     */
    public static function compose(array $moments, ?array $management, ?array $awards, CarbonImmutable $today, array $upcoming = []): array
    {
        return [
            'moments' => $moments,
            // Which one opens today. Every moment is in the page; the pill steps through the rest.
            'moments_start' => $moments === [] ? 0 : $today->dayOfYear % count($moments),
            'management' => $management,
            'awards' => $awards,
            'upcoming' => $upcoming,
        ];
    }

    /**
     * The calendar dates (this run) whose birthdays show today (CR-13).
     *
     * A birthday always shows on its own actual day. On top of that, when today is a
     * working day, any birthday that falls in the non-working run immediately after
     * today (a weekend and/or public holidays) shows early too — so it always lands
     * on the last working day before it, never lost inside days nobody is at work.
     *
     * @return list<CarbonImmutable>
     */
    public static function celebratedOn(CarbonImmutable $today, callable $isWorkingDay): array
    {
        $dates = [$today];

        if ($isWorkingDay($today)) {
            $cursor = $today->addDay();
            while (! $isWorkingDay($cursor)) {
                $dates[] = $cursor;
                $cursor = $cursor->addDay();
            }
        }

        return $dates;
    }

    /**
     * One moment per colleague celebrated today (CR-13, see celebratedOn()). Each
     * moment carries the real birthday date and a slimmed-down employee payload so
     * the band can show an avatar/role and the wishes composer can target the right
     * person, even on the advance (weekend/holiday) case.
     *
     * @param  iterable<Employee>  $people
     * @param  list<CarbonImmutable>  $celebratedDates  from celebratedOn(), same $today
     * @return list<Moment&array{date: string, employee: array<string, mixed>}>
     */
    public static function birthdayMoments(iterable $people, CarbonImmutable $today, array $celebratedDates, string $tenantName, ?int $selfId = null): array
    {
        $out = [];
        foreach ($people as $person) {
            if ($person->date_of_birth === null) {
                continue;
            }

            $on = null;
            foreach ($celebratedDates as $date) {
                if ((int) $person->date_of_birth->format('n') === $date->month
                    && (int) $person->date_of_birth->format('j') === $date->day) {
                    $on = $date;

                    break;
                }
            }
            if ($on === null) {
                continue;
            }

            $name = $person->display_name;
            $employeePayload = [
                'id' => $person->id,
                'display_name' => $name,
                'position' => $person->position,
                'initials' => $person->initials,
                'avatar_color' => $person->avatar_color,
            ];

            if (! $on->isSameDay($today)) {
                $formatted = $on->format('D j M');
                $out[] = [
                    'kind' => 'birthday',
                    'kicker' => ['en' => 'This weekend', 'ms' => 'Hujung minggu ini'],
                    'title' => [
                        'en' => "{$name}'s birthday is on {$formatted}",
                        'ms' => "Hari lahir {$name} pada {$formatted}",
                    ],
                    'sub' => ['en' => 'Wish them before the weekend.', 'ms' => 'Ucapkan sebelum hujung minggu.'],
                    'cta' => null,
                    'art' => 'cake',
                    'date' => $on->toDateString(),
                    'employee' => $employeePayload,
                ];

                continue;
            }

            if ($person->id === $selfId) {
                $out[] = [
                    'kind' => 'birthday',
                    'kicker' => ['en' => 'Today', 'ms' => 'Hari ini'],
                    'title' => ['en' => "Happy birthday, {$name}!", 'ms' => "Selamat hari lahir, {$name}!"],
                    'sub' => ['en' => 'From everyone here. Have a good one.', 'ms' => 'Daripada kami semua. Semoga ceria.'],
                    'cta' => null,
                    'art' => 'cake',
                    'date' => $on->toDateString(),
                    'employee' => $employeePayload,
                ];

                continue;
            }

            $out[] = [
                'kind' => 'birthday',
                'kicker' => ['en' => 'Today', 'ms' => 'Hari ini'],
                'title' => ['en' => "It's {$name}'s birthday", 'ms' => "Hari lahir {$name}"],
                'sub' => [
                    'en' => "Happy birthday, {$name}! \u{2013} from all of us at {$tenantName}",
                    'ms' => "Selamat Hari Lahir, {$name}! \u{2013} daripada kami semua di {$tenantName}",
                ],
                'cta' => null,
                'art' => 'cake',
                'date' => $on->toDateString(),
                'employee' => $employeePayload,
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

    /**
     * "Coming up" line under the moments block: non-private active staff whose
     * birthday falls tomorrow..+7 days, excluding anyone already celebrated today
     * (they are in the band, not the coming-up line). Chronological order.
     *
     * @param  iterable<Employee>  $people
     * @param  array<int, bool>  $celebratedTodayIds  employee id => true, for exclusion
     * @return list<array{name: string, date: string}>
     */
    public static function upcomingBirthdays(iterable $people, CarbonImmutable $today, array $celebratedTodayIds = []): array
    {
        $out = [];
        foreach (range(1, 7) as $offset) {
            $day = $today->addDays($offset);
            foreach ($people as $person) {
                if ($person->date_of_birth === null || isset($celebratedTodayIds[$person->id])) {
                    continue;
                }
                if ((int) $person->date_of_birth->format('n') === $day->month
                    && (int) $person->date_of_birth->format('j') === $day->day) {
                    $out[] = ['name' => $person->display_name, 'date' => $day->toDateString()];
                }
            }
        }

        return $out;
    }

    /**
     * The management slot (CR-32 owns the slot, CR-17 fills it): director, HR and
     * senior management, every day. Until CR-17 lands it names what will be here.
     *
     * @return array{kicker: array{en: string, ms: string}, title: array{en: string, ms: string}, sub: array{en: string, ms: string}}
     */
    /**
     * CR-17: lateness today and overdue-by-Primary-Owner, always rendered for
     * FINAL_APPROVAL_ROLES (CR32Test pins exactly one band, quiet day or not) — an
     * empty panel just shows its own "nothing to show" line.
     *
     * @param  list<array{employee_id:int,name:string,status_en:string,status_ms:string}>  $lateness
     * @param  list<array{owner_id:int,owner_name:string,cards:list<array{id:int,title:string,days_overdue:int}>}>  $overdue
     */
    public static function managementSlot(array $lateness, array $overdue, string $scope = 'company'): array
    {
        return [
            'kicker' => ['en' => 'Management', 'ms' => 'Pengurusan'],
            'title' => ['en' => 'Lateness today and overdue by Primary Owner', 'ms' => 'Lewat hari ini dan tertunggak mengikut Pemilik Utama'],
            'sub' => ['en' => 'Excludes leave, WFH and client-site staff. No grace applied.', 'ms' => 'Tidak termasuk cuti, WFH dan lapangan pelanggan. Tiada tempoh bertolak ansur.'],
            'lateness' => $lateness,
            'overdue' => $overdue,
            'scope' => $scope,
        ];
    }

    /**
     * The awards slot (CR-32 owns the window, CR-14 fills it): everyone, from the
     * first working day of the month to the 7th inclusive.
     *
     * @return array{kicker: array{en: string, ms: string}, title: array{en: string, ms: string}, sub: array{en: string, ms: string}}
     */
    public static function awardsSlot(CarbonImmutable $today): array
    {
        $month = $today->format('F');

        return [
            'kicker' => ['en' => 'Awards', 'ms' => 'Anugerah'],
            'title' => ['en' => "{$month}'s awards", 'ms' => "Anugerah {$today->locale('ms')->translatedFormat('F')}"],
            'sub' => ['en' => 'The carousel opens here, one award per slide, once awards are given.', 'ms' => 'Karusel dibuka di sini, satu anugerah setiap slaid, setelah anugerah diberikan.'],
        ];
    }

    /**
     * Whether today falls in the awards window: from the month's first working day
     * (weekends and public holidays are not working days) through the 7th.
     *
     * @param  callable(CarbonImmutable): bool  $isWorkingDay
     */
    public static function awardsWindowOpen(CarbonImmutable $today, callable $isWorkingDay): bool
    {
        if ($today->day > 7) {
            return false;
        }

        $first = $today->startOfMonth();
        while (! $isWorkingDay($first) && $first->day <= 7) {
            $first = $first->addDay();
        }

        return $today->day >= $first->day;
    }
}
