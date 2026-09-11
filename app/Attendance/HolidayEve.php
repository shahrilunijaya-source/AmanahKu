<?php

declare(strict_types=1);

namespace App\Attendance;

use App\Models\PublicHoliday;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Is a given day the eve of a public holiday, and when is work back on (CR-20)?
 *
 * "Eve" means the last working day before the holiday, so a Friday before a Monday
 * holiday counts, and so does the day before a run of holidays. The holiday reported
 * is the first one in the run; the next working day is the first weekday after the run
 * that is not itself a holiday. Weekends are the only non-working days besides holidays.
 */
class HolidayEve
{
    public const GENERIC_EN = 'Happy holiday! Rest well, see you back soon.';

    public const GENERIC_MS = 'Selamat bercuti! Rehat secukupnya, jumpa lagi nanti.';

    /** Days to look ahead before giving up; a longer run is not a holiday, it is a shutdown. */
    private const MAX_RUN = 10;

    /**
     * @return array{holiday: PublicHoliday, next_working_day: CarbonImmutable}|null
     */
    public function forDay(CarbonInterface $day): ?array
    {
        $day = CarbonImmutable::instance($day)->startOfDay();

        $holidays = PublicHoliday::query()
            ->whereBetween('date', [$day->addDay()->toDateString(), $day->addDays(self::MAX_RUN)->toDateString()])
            ->orderBy('date')
            ->get()
            ->keyBy(fn (PublicHoliday $h): string => $h->date->toDateString());

        $first = null;
        $cursor = $day->addDay();
        for ($i = 0; $i < self::MAX_RUN; $i++, $cursor = $cursor->addDay()) {
            $holiday = $holidays->get($cursor->toDateString());
            if ($holiday !== null) {
                $first ??= $holiday;

                continue;
            }
            if ($cursor->isWeekend()) {
                continue;
            }

            break;
        }

        if ($first === null) {
            return null;
        }

        return ['holiday' => $first, 'next_working_day' => $cursor];
    }

    /**
     * The greeting payload the screen and the notification both render.
     *
     * @return array{holiday_id: int, name: string, date: string, greeting_en: string, greeting_ms: string, next_working_day: string, curated: bool}
     */
    public function payload(PublicHoliday $holiday, CarbonImmutable $nextWorkingDay): array
    {
        $en = trim((string) $holiday->greeting_en);
        $ms = trim((string) $holiday->greeting_ms);

        return [
            'holiday_id' => (int) $holiday->id,
            'name' => (string) $holiday->name,
            'date' => $holiday->date->toDateString(),
            'greeting_en' => $en !== '' ? $en : ($ms !== '' ? $ms : self::GENERIC_EN),
            'greeting_ms' => $ms !== '' ? $ms : ($en !== '' ? $en : self::GENERIC_MS),
            'next_working_day' => $nextWorkingDay->toDateString(),
            'curated' => $en !== '' || $ms !== '',
        ];
    }

    /** Per person per holiday: a second clock-out, or the 5:30 sweep, never repeats it. */
    public static function dedupeKey(int $holidayId): string
    {
        return 'holiday-eve-'.$holidayId;
    }
}
