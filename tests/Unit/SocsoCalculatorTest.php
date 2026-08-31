<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Payroll\SocsoCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Every assertion here is against the official PERKESO Third Schedule (Act 4),
 * transcribed from docs/statutory/socso-act4.pdf into tests/Fixtures/socso-third-schedule-act4.csv.
 * If a row disagrees, the code is wrong — not the fixture.
 */
class SocsoCalculatorTest extends TestCase
{
    private SocsoCalculator $socso;

    protected function setUp(): void
    {
        parent::setUp();
        $this->socso = new SocsoCalculator;
    }

    /**
     * Walk all 65 published rows, checking just above the floor, the middle and the top
     * of each band (a band is `from` exclusive, `to` inclusive — the boundary itself
     * belongs to the lower band, so `from` is never tested directly), for both
     * categories and both SKBBK opt-in states. The ceiling row has no upper bound —
     * checked at its floor and well above it instead.
     */
    public function test_it_matches_every_row_of_the_official_third_schedule(): void
    {
        $rows = $this->scheduleRows();
        $this->assertCount(65, $rows, 'Fixture no longer holds the full published schedule.');

        foreach ($rows as $row) {
            [$from, $to, $c1Er, $c1Inv, $c1Skbbk, $c2Er, $c2Skbbk] = $row;
            $wages = $to === null
                ? [$from + 0.01, $from + 1000.0]
                : [$from + 0.01, round(($from + $to) / 2, 2), $to];

            foreach ($wages as $wage) {
                // Category 1, opted out of SKBBK.
                $got = $this->socso->contribution($wage, 1, false);
                $this->assertSame($c1Inv, $got['employee'], "cat1 no-skbbk, wages {$wage}: employee");
                $this->assertSame($c1Er, $got['employer'], "cat1 no-skbbk, wages {$wage}: employer");
                $this->assertSame(0.0, $got['skbbk'], "cat1 no-skbbk, wages {$wage}: skbbk");

                // Category 1, opted in to SKBBK.
                $got = $this->socso->contribution($wage, 1, true);
                $this->assertSame(round($c1Inv + $c1Skbbk, 2), $got['employee'], "cat1 skbbk, wages {$wage}: employee");
                $this->assertSame($c1Er, $got['employer'], "cat1 skbbk, wages {$wage}: employer");
                $this->assertSame($c1Skbbk, $got['skbbk'], "cat1 skbbk, wages {$wage}: skbbk");

                // Category 2, opted out of SKBBK.
                $got = $this->socso->contribution($wage, 2, false);
                $this->assertSame(0.0, $got['employee'], "cat2 no-skbbk, wages {$wage}: employee");
                $this->assertSame($c2Er, $got['employer'], "cat2 no-skbbk, wages {$wage}: employer");
                $this->assertSame(0.0, $got['skbbk'], "cat2 no-skbbk, wages {$wage}: skbbk");

                // Category 2, opted in to SKBBK.
                $got = $this->socso->contribution($wage, 2, true);
                $this->assertSame($c2Skbbk, $got['employee'], "cat2 skbbk, wages {$wage}: employee");
                $this->assertSame($c2Er, $got['employer'], "cat2 skbbk, wages {$wage}: employer");
                $this->assertSame($c2Skbbk, $got['skbbk'], "cat2 skbbk, wages {$wage}: skbbk");
            }
        }
    }

    public function test_wages_above_the_ceiling_use_the_top_band(): void
    {
        $atCeiling = $this->socso->contribution(6000.0, 1, true);
        $above = $this->socso->contribution(50000.0, 1, true);
        $this->assertSame($atCeiling, $above);
    }

    public function test_zero_wage_contributes_nothing(): void
    {
        $got = $this->socso->contribution(0.0, 1, true);
        $this->assertSame(['employee' => 0.0, 'employer' => 0.0, 'skbbk' => 0.0], $got);
    }

    /**
     * @return array<int, array{0: float, 1: float|null, 2: float, 3: float, 4: float, 5: float, 6: float}>
     */
    private function scheduleRows(): array
    {
        $handle = fopen(__DIR__.'/../Fixtures/socso-third-schedule-act4.csv', 'r');
        fgetcsv($handle, escape: '');   // header

        $rows = [];
        while (($row = fgetcsv($handle, escape: '')) !== false) {
            [$no, $from, $to, $c1Er, $c1Inv, $c1Skbbk, $c1Total, $c2Er, $c2Skbbk, $c2Total] = $row;
            $rows[] = [
                (float) $from,
                $to === '' ? null : (float) $to,
                (float) $c1Er,
                (float) $c1Inv,
                (float) $c1Skbbk,
                (float) $c2Er,
                (float) $c2Skbbk,
            ];
        }
        fclose($handle);

        return $rows;
    }
}
