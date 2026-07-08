<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Employee;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * PERKESO contribution category boundary: 60th birthday flips an employee from
 * Category 1 (full SOCSO + EIS) to Category 2 (Employment-Injury only, no EIS).
 * Money-path boundary — a wrong category mis-states every payslip after 60.
 */
class StatutoryCategoryTest extends TestCase
{
    private function employeeBorn(?string $dob): Employee
    {
        return new Employee(['date_of_birth' => $dob]);
    }

    public function test_employee_is_category_2_on_their_60th_birthday(): void
    {
        $asOf = Carbon::parse('2026-07-02');

        $this->assertSame(2, $this->employeeBorn('1966-07-02')->statutoryCategory($asOf));
    }

    public function test_employee_is_category_1_the_day_before_their_60th_birthday(): void
    {
        $asOf = Carbon::parse('2026-07-02');

        $this->assertSame(1, $this->employeeBorn('1966-07-03')->statutoryCategory($asOf));
    }

    public function test_employee_well_past_60_is_category_2(): void
    {
        $asOf = Carbon::parse('2026-07-02');

        $this->assertSame(2, $this->employeeBorn('1950-01-15')->statutoryCategory($asOf));
    }

    public function test_missing_dob_defaults_to_category_1(): void
    {
        $this->assertSame(1, $this->employeeBorn(null)->statutoryCategory(Carbon::parse('2026-07-02')));
    }
}
