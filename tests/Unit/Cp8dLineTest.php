<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Payroll\Cp8dLine;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * Acceptance test per the task brief: reproduce both worked examples from
 * docs/statutory/cp8d-information-layout.pdf, character for character.
 */
class Cp8dLineTest extends TestCase
{
    /** @return array{name: string, tin: ?string, identification: string, category: int, employee_status: ?int, retirement_or_end_date: ?Carbon, tax_borne_by_employer: ?int, children_count: ?int, total_qualifying_child_relief: ?float, total_gross_remuneration: ?float, benefits_in_kind: ?float, living_accommodation: ?float, esos_benefit: ?float, tax_exempt_allowances: ?float, tp1_relief: ?float, tp1_zakat: ?float, epf_contribution: ?float, zakat_salary_deduction: ?float, mtd: ?float, cp38: ?float, medical_insurance: ?float, socso_contribution: ?float} */
    private function baseRow(): array
    {
        // Field values exactly as the PDF's worked example table lists them (values in
        // sen where the field type is decimal, whole ringgit — with sen in the input
        // amount — where the field type is integer, so truncation is exercised).
        return [
            'name' => 'Ali bin Ahmad',
            'tin' => '03770324020',
            'identification' => '730510125580',
            'category' => 3,
            'employee_status' => 2,
            'retirement_or_end_date' => Carbon::create(2027, 12, 15),
            'tax_borne_by_employer' => 2,
            'children_count' => 1,
            'total_qualifying_child_relief' => 2000.0,
            'total_gross_remuneration' => 50000.70,   // truncates to 50000
            'benefits_in_kind' => 4200.80,             // truncates to 4200
            'living_accommodation' => 12000.90,        // truncates to 12000
            'esos_benefit' => 1300.80,                 // truncates to 1300
            'tax_exempt_allowances' => 445.60,         // truncates to 445
            'tp1_relief' => 2200.50,                   // truncates to 2200
            'tp1_zakat' => 1400.30,                    // decimal field — sen kept
            'epf_contribution' => 3600.90,             // truncates to 3600
            'zakat_salary_deduction' => 1700.20,       // decimal field — sen kept
            'mtd' => 2555.25,                          // decimal field — sen kept
            'cp38' => 1822.63,                         // decimal field — sen kept
            'medical_insurance' => 2210.90,            // truncates to 2210
            'socso_contribution' => 150.90,            // truncates to 150
        ];
    }

    public function test_worked_example_1_full_particulars(): void
    {
        $expected = 'Ali bin Ahmad|03770324020|730510125580|3|2|15-12-2027|2|1|2000|50000|4200|12000|1300|445'
            .'|2200|1400.30|3600|1700.20|2555.25|1822.63|2210|150';

        $this->assertSame($expected, Cp8dLine::format($this->baseRow()));
    }

    public function test_worked_example_2_no_accommodation_esos_or_cp38(): void
    {
        $row = $this->baseRow();
        $row['living_accommodation'] = null;
        $row['esos_benefit'] = null;
        $row['cp38'] = null;

        // The PDF's own literal line is:
        //   Ali bin Ahmad|03770324020|730510125580|3|2|15-12-2027|2|1|2000|50000|4200|||445
        //   |2200|1400.30|3600|1700.20|2555.25||2210|150|
        // — note the trailing "|" after field 22 (150), which is already populated in
        // BOTH examples. No rule in the spec produces a delimiter after a populated
        // final field; example 1 (identical field 22 value, no trailing pipe) confirms
        // it. Treated as a PDF typo per the task brief's "report what you could not
        // implement" — this is that one deviation. Asserted without the trailing pipe.
        $expected = 'Ali bin Ahmad|03770324020|730510125580|3|2|15-12-2027|2|1|2000|50000|4200|||445'
            .'|2200|1400.30|3600|1700.20|2555.25||2210|150';

        $this->assertSame($expected, Cp8dLine::format($row));
    }

    public function test_sen_is_truncated_not_rounded_on_integer_fields(): void
    {
        $row = $this->baseRow();
        $row['total_gross_remuneration'] = 50000.99; // would round to 50001 — must not

        $this->assertStringContainsString('|50000|', Cp8dLine::format($row));
    }

    public function test_sen_is_kept_on_decimal_fields(): void
    {
        $row = $this->baseRow();
        $row['mtd'] = 2555.256; // stored precision beyond a cent

        $this->assertStringContainsString('|2555.26|', Cp8dLine::format($row));
    }

    public function test_zero_optional_field_prints_blank_not_zero(): void
    {
        $row = $this->baseRow();
        $row['cp38'] = 0.0;

        $this->assertStringContainsString('|2555.25||2210|', Cp8dLine::format($row));
    }

    public function test_missing_tin_prints_blank(): void
    {
        $row = $this->baseRow();
        $row['tin'] = null;

        $this->assertStringStartsWith('Ali bin Ahmad||730510125580|', Cp8dLine::format($row));
    }

    public function test_pipe_in_name_is_stripped(): void
    {
        $row = $this->baseRow();
        $row['name'] = 'Ali | Ahmad';

        $this->assertStringStartsWith('Ali   Ahmad|', Cp8dLine::format($row));
    }

    public function test_filename_format(): void
    {
        $this->assertSame('P2900030000_2025.txt', Cp8dLine::filename('2900030000', 2025));
    }
}
