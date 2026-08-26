<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Payroll\EpfCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every assertion here is against the official KWSP Third Schedule effective 1 October 2025,
 * transcribed from the published PDF into tests/Fixtures/epf-third-schedule-2025-10.csv.
 * If a row disagrees, the code is wrong — not the fixture.
 */
class EpfCalculatorTest extends TestCase
{
    private EpfCalculator $epf;

    protected function setUp(): void
    {
        parent::setUp();
        $this->epf = new EpfCalculator;
    }

    /**
     * Walk all 1,203 published rows of Parts A, C and E, checking the bottom, middle and top
     * of each band — every wage in a band must produce that band's fixed amounts.
     */
    public function test_it_matches_every_row_of_the_official_third_schedule(): void
    {
        $rows = $this->scheduleRows();
        $this->assertCount(1203, $rows, 'Fixture no longer holds the full published schedule.');

        foreach ($rows as $row) {
            [$part, $from, $to, $employer, $employee] = $row;

            foreach ([$from, round(($from + $to) / 2, 2), $to] as $wages) {
                $got = $this->epf->contribution($wages, $part);
                $this->assertSame($employee, $got['employee'], "Part {$part}, wages {$wages}: employee share");
                $this->assertSame($employer, $got['employer'], "Part {$part}, wages {$wages}: employer share");
            }
        }
    }

    public function test_wages_of_ten_ringgit_or_less_are_not_contributable(): void
    {
        foreach (['A', 'C', 'E', 'F'] as $part) {
            $this->assertSame(
                ['employee' => 0.0, 'employer' => 0.0],
                $this->epf->contribution(10.00, $part),
            );
        }
    }

    /** Above RM20,000 the schedule stops and exact percentages apply, still rounded up. */
    public function test_above_twenty_thousand_it_uses_exact_percentages(): void
    {
        // Part A: employee 11%, employer 12%.
        $this->assertSame(
            ['employee' => 2751.0, 'employer' => 3001.0],   // 11% / 12% of 25,000.50, rounded up
            $this->epf->contribution(25000.50, 'A'),
        );

        // Part C: employee 5.5%, employer 6%.
        $this->assertSame(
            ['employee' => 1375.0, 'employer' => 1500.0],
            $this->epf->contribution(25000.00, 'C'),
        );

        // Part E: employee nil, employer 4%.
        $this->assertSame(
            ['employee' => 0.0, 'employer' => 1000.0],
            $this->epf->contribution(25000.00, 'E'),
        );
    }

    /** Part F (non-citizens, mandatory since 1 Oct 2025) is 2% of actual wages, no bands. */
    public function test_part_f_is_two_percent_of_actual_wages(): void
    {
        $this->assertSame(
            ['employee' => 62.0, 'employer' => 62.0],   // 2% of 3,050.10 = 61.002 → 62
            $this->epf->contribution(3050.10, 'F'),
        );

        $this->assertSame(
            ['employee' => 100.0, 'employer' => 100.0],
            $this->epf->contribution(5000.00, 'F'),
        );
    }

    /**
     * @return array<string, array{0: string, 1: int|null, 2: bool, 3: string|null}>
     */
    public static function partCases(): array
    {
        return [
            'citizen under 60' => ['citizen', 34, false, 'A'],
            'citizen with no date of birth on file' => ['citizen', null, false, 'A'],
            'citizen at 60' => ['citizen', 60, false, 'E'],
            'permanent resident under 60' => ['pr', 41, false, 'A'],
            'permanent resident at 60' => ['pr', 63, false, 'C'],
            'foreign worker' => ['foreign', 29, false, 'F'],
            'foreign worker who elected before 1998' => ['foreign', 45, true, 'A'],
            'foreign pre-1998 elector at 60' => ['foreign', 62, true, 'C'],
            'anyone at 75' => ['citizen', 75, false, null],
        ];
    }

    #[DataProvider('partCases')]
    public function test_it_resolves_the_right_part(string $nationality, ?int $age, bool $elected, ?string $expected): void
    {
        $this->assertSame($expected, $this->epf->part($nationality, $age, $elected));
    }

    /**
     * @return array<int, array{0: string, 1: float, 2: float, 3: float, 4: float}>
     */
    private function scheduleRows(): array
    {
        $handle = fopen(__DIR__.'/../Fixtures/epf-third-schedule-2025-10.csv', 'r');
        fgetcsv($handle, escape: '');   // header

        $rows = [];
        while (($row = fgetcsv($handle, escape: '')) !== false) {
            $rows[] = [(string) $row[0], (float) $row[1], (float) $row[2], (float) $row[3], (float) $row[4]];
        }
        fclose($handle);

        return $rows;
    }
}
