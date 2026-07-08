<?php

namespace Tests\Unit;

use App\Services\Payroll\StatutoryBrackets;
use PHPUnit\Framework\TestCase;

class StatutoryBracketsTest extends TestCase
{
    public function test_bands_are_contiguous_and_non_overlapping_up_to_the_ceiling(): void
    {
        $bands = StatutoryBrackets::socso(1);

        $this->assertNotEmpty($bands);
        $this->assertSame(0.0, $bands[0]['from']);
        $this->assertSame(StatutoryBrackets::WAGE_CEILING, $bands[count($bands) - 1]['to']);

        $prevTo = null;
        foreach ($bands as $b) {
            $this->assertGreaterThan($b['from'], $b['to'], 'each band must have to > from');
            if ($prevTo !== null) {
                $this->assertSame($prevTo, $b['from'], 'no gaps/overlaps between bands');
            }
            $prevTo = $b['to'];
        }
    }

    public function test_lookup_picks_the_band_by_from_exclusive_to_inclusive(): void
    {
        $bands = [
            ['from' => 0.0, 'to' => 100.0, 'ee' => 0.50, 'er' => 1.00],
            ['from' => 100.0, 'to' => 200.0, 'ee' => 1.50, 'er' => 2.00],
        ];

        // Exactly on a boundary belongs to the lower band (to is inclusive).
        $this->assertSame(0.50, StatutoryBrackets::lookup($bands, 100.0)['ee']);
        // Just over the boundary moves to the next band.
        $this->assertSame(1.50, StatutoryBrackets::lookup($bands, 100.01)['ee']);
    }

    public function test_wage_above_ceiling_uses_the_top_band(): void
    {
        $bands = StatutoryBrackets::socso(1);
        $top = $bands[count($bands) - 1];

        $this->assertSame($top['ee'], StatutoryBrackets::lookup($bands, 99999.0)['ee']);
        $this->assertSame($top['er'], StatutoryBrackets::lookup($bands, 99999.0)['er']);
    }

    public function test_category_two_socso_has_no_employee_contribution(): void
    {
        foreach (StatutoryBrackets::socso(2) as $b) {
            $this->assertSame(0.0, $b['ee'], 'Category 2 (≥60) employee SOCSO is 0');
            $this->assertGreaterThanOrEqual(0.0, $b['er']);
        }
    }

    public function test_category_two_eis_schedule_is_empty(): void
    {
        $this->assertSame([], StatutoryBrackets::eis(2));
        $this->assertSame(['ee' => 0.0, 'er' => 0.0], StatutoryBrackets::lookup(StatutoryBrackets::eis(2), 3000.0));
    }

    /**
     * Reference-row guard. SKIPPED while the table is placeholder data. Once the official
     * PERKESO Jadual Caruman is transcribed (IS_PLACEHOLDER = false), assert a few known
     * published rows here and remove the skip so accuracy is locked by a test.
     */
    public function test_known_reference_rows_match_official_schedule(): void
    {
        if (StatutoryBrackets::IS_PLACEHOLDER) {
            $this->markTestSkipped('Bracket table is placeholder; fill official PERKESO rows then assert here.');
        }

        // TODO once real figures land, e.g.:
        // $this->assertSame(EE, StatutoryBrackets::lookup(StatutoryBrackets::socso(1), 2950.0)['ee']);
        $this->fail('IS_PLACEHOLDER is false but no reference rows asserted — add them.');
    }
}
