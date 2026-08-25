<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Payroll\EisCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Every assertion here is against the official PERKESO Second Schedule (Act 800),
 * transcribed from docs/statutory/eis-act800.pdf into tests/Fixtures/eis-second-schedule-act800.csv.
 * If a row disagrees, the code is wrong — not the fixture.
 */
class EisCalculatorTest extends TestCase
{
    private EisCalculator $eis;

    protected function setUp(): void
    {
        parent::setUp();
        $this->eis = new EisCalculator;
    }

    /**
     * Walk all 65 published rows, checking just above the floor, the middle and the top
     * of each band (a band is `from` exclusive, `to` inclusive — the boundary itself
     * belongs to the lower band, so `from` is never tested directly). The ceiling row
     * has no upper bound — checked at its floor and well above it instead.
     */
    public function test_it_matches_every_row_of_the_official_second_schedule(): void
    {
        $rows = $this->scheduleRows();
        $this->assertCount(65, $rows, 'Fixture no longer holds the full published schedule.');

        foreach ($rows as $row) {
            [$from, $to, $employer, $employee] = $row;
            $wages = $to === null
                ? [$from + 0.01, $from + 1000.0]
                : [$from + 0.01, round(($from + $to) / 2, 2), $to];

            foreach ($wages as $wage) {
                $got = $this->eis->contribution($wage, 1);
                $this->assertSame($employee, $got['employee'], "wages {$wage}: employee");
                $this->assertSame($employer, $got['employer'], "wages {$wage}: employer");
            }
        }
    }

    public function test_category_two_is_not_covered_at_all(): void
    {
        $this->assertSame(['employee' => 0.0, 'employer' => 0.0], $this->eis->contribution(3000.0, 2));
        $this->assertSame(['employee' => 0.0, 'employer' => 0.0], $this->eis->contribution(0.0, 2));
    }

    public function test_wages_above_the_ceiling_use_the_top_band(): void
    {
        $atCeiling = $this->eis->contribution(6000.0, 1);
        $above = $this->eis->contribution(50000.0, 1);
        $this->assertSame($atCeiling, $above);
    }

    /**
     * @return array<int, array{0: float, 1: float|null, 2: float, 3: float}>
     */
    private function scheduleRows(): array
    {
        $handle = fopen(__DIR__.'/../Fixtures/eis-second-schedule-act800.csv', 'r');
        fgetcsv($handle, escape: '');   // header

        $rows = [];
        while (($row = fgetcsv($handle, escape: '')) !== false) {
            [$no, $from, $to, $employer, $employee] = $row;
            $rows[] = [(float) $from, $to === '' ? null : (float) $to, (float) $employer, (float) $employee];
        }
        fclose($handle);

        return $rows;
    }
}
