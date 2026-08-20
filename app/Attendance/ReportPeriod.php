<?php

declare(strict_types=1);

namespace App\Attendance;

use Carbon\CarbonImmutable;

/**
 * The period an attendance report covers, resolved from query input and clamped
 * so it can never reach into the future. Owns its own labels because the caption
 * above the totals must name the period it actually totals — a block reading
 * "month to date" over one week's figures is worse than no caption.
 */
final class ReportPeriod
{
    private const GRANS = ['day', 'week', 'month', 'custom'];

    /** Carbon ships no bundled BM locale here; same hand-map AttendanceReportController used. */
    private const MS_DAYS = ['Ahd', 'Isn', 'Sel', 'Rab', 'Kha', 'Jum', 'Sab'];

    /** @var array<int, string> */
    private const MS_MONTHS = [1 => 'Januari', 'Februari', 'Mac', 'April', 'Mei', 'Jun',
        'Julai', 'Ogos', 'September', 'Oktober', 'November', 'Disember'];

    /** @var array<int, string> */
    private const MS_MONTHS_SHORT = [1 => 'Jan', 'Feb', 'Mac', 'Apr', 'Mei', 'Jun',
        'Jul', 'Ogos', 'Sep', 'Okt', 'Nov', 'Dis'];

    private function __construct(
        public readonly string $gran,
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly int $offset,
        public readonly bool $canPrev,
        public readonly bool $canNext,
    ) {}

    /** @param array<string, mixed> $query */
    public static function fromRequest(array $query, CarbonImmutable $today): self
    {
        $today = $today->startOfDay();
        $gran = is_string($query['gran'] ?? null) && in_array($query['gran'], self::GRANS, true)
            ? $query['gran']
            : 'month';

        if ($gran === 'custom') {
            $custom = self::custom($query, $today);
            if ($custom !== null) {
                return $custom;
            }
            $gran = 'month';
        }

        // Negative only: stepping forward past the current period is meaningless.
        $offset = min(0, (int) ($query['offset'] ?? 0));

        [$from, $to] = match ($gran) {
            'day' => self::dayWindow($today, $offset),
            'week' => self::weekWindow($today, $offset),
            default => self::monthWindow($today, $offset),
        };

        return new self($gran, $from, $to, $offset, true, $offset < 0);
    }

    /** @param array<string, mixed> $query */
    private static function custom(array $query, CarbonImmutable $today): ?self
    {
        $from = self::parse($query['from'] ?? null);
        $to = self::parse($query['to'] ?? null);

        if ($from === null || $to === null || $from->gt($to)) {
            return null;
        }

        // A range that sits entirely in the future clamps to nothing at all; hand it
        // back as null so the caller falls through to the month rather than rendering
        // an inside-out window that produces no working days and no explanation.
        $to = $to->min($today);
        if ($from->gt($to)) {
            return null;
        }

        return new self('custom', $from, $to, 0, false, false);
    }

    private static function parse(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array{CarbonImmutable, CarbonImmutable} */
    private static function dayWindow(CarbonImmutable $today, int $offset): array
    {
        $d = $today->addDays($offset);

        return [$d, $d];
    }

    /** @return array{CarbonImmutable, CarbonImmutable} */
    private static function weekWindow(CarbonImmutable $today, int $offset): array
    {
        $start = $today->addWeeks($offset)->startOfWeek(CarbonImmutable::MONDAY);

        return [$start, $start->addDays(4)->min($today)];
    }

    /** @return array{CarbonImmutable, CarbonImmutable} */
    private static function monthWindow(CarbonImmutable $today, int $offset): array
    {
        $start = $today->addMonths($offset)->startOfMonth();

        return [$start, $start->endOfMonth()->startOfDay()->min($today)];
    }

    public function label(string $lang): string
    {
        $ms = $lang === 'ms';

        return match ($this->gran) {
            'day' => ($ms ? self::MS_DAYS[$this->from->dayOfWeek] : $this->from->format('D'))
                .', '.$this->from->day.' '.$this->shortMonth($this->from, $ms),
            'week', 'custom' => $this->from->day.' – '.$this->to->day.' '.$this->shortMonth($this->to, $ms),
            default => ($ms ? self::MS_MONTHS[(int) $this->from->month] : $this->from->format('F'))
                .' '.$this->from->year,
        };
    }

    public function rangeLabel(string $lang): string
    {
        $ms = $lang === 'ms';

        return $this->from->equalTo($this->to)
            ? $this->from->day.' '.$this->shortMonth($this->from, $ms).' '.$this->from->year
            : $this->from->day.' – '.$this->to->day.' '.$this->shortMonth($this->to, $ms).' '.$this->to->year;
    }

    private function shortMonth(CarbonImmutable $at, bool $ms): string
    {
        return $ms ? self::MS_MONTHS_SHORT[(int) $at->month] : $at->format('M');
    }

    /** Which caption the totals block wears. 'weekPast'/'dayPast' get a named date instead. */
    public function captionKey(): string
    {
        if ($this->gran === 'custom') {
            return 'custom';
        }
        if ($this->offset === 0) {
            return $this->gran;
        }

        return $this->gran.'Past';
    }

    /**
     * Mon–Fri inside the window, plus any date on which somebody actually has a
     * record — a weekend shift is real work and must not vanish from the ledger.
     *
     * @param  list<string>  $recordDates  Y-m-d
     * @return list<string>
     */
    public function workingDays(array $recordDates): array
    {
        $days = [];
        $cursor = $this->from;

        while ($cursor->lte($this->to)) {
            $date = $cursor->toDateString();
            if ($cursor->isWeekday() || in_array($date, $recordDates, true)) {
                $days[] = $date;
            }
            $cursor = $cursor->addDay();
        }

        return $days;
    }
}
