<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Support\CsvImport;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;

/**
 * Reads the Summary tab of HR's own salary listing (downloaded as CSV): one year-to-date
 * row per person for the months paid before AmanahKu took over, with the columns the
 * listing already has. Turns each row into Take On figures (PayrollOpeningFigure):
 *
 * - B1(a) salary is BASIC less UNPAID LEAVE. The listing's GROSS is before unpaid leave,
 *   which it only takes off on the way to NET, but tax is on what was actually earned.
 * - MEDICAL, MILEAGE and OTHERS are claims paid back against receipts: not income, not
 *   on Form EA (same as this app's own claim-reimbursement line). MEDICAL is still kept,
 *   because it uses up the yearly medical claim cap.
 * - ADVANCE, DEDUCTION and NET SALARY only moved take-home pay, so they are not read.
 *
 * Rows are matched to staff by a STAFF ID column when the sheet has one, otherwise by
 * name. Rows naming nobody on file (department subtotals, interns never set up here)
 * are skipped and listed back to HR; a bad number or a row whose parts do not add up to
 * its GROSS is an error, and the caller saves nothing when there is any error.
 */
final class TakeOnImport
{
    /** @var array<string, string> field => listing header (matched lower-cased) */
    private const array COLUMNS = [
        'basic' => 'basic', 'bonus' => 'bonus', 'allowance' => 'allowance', 'medical' => 'medical',
        'mileage' => 'mileage', 'others' => 'others', 'gross' => 'gross', 'epf' => 'epf', 'tax' => 'tax',
        'socso' => 'socso', 'eis' => 'eis', 'zakat' => 'zakat', 'unpaid' => 'unpaid leave', 'cp38' => 'cp38',
        'employer_epf' => 'employer epf', 'employer_socso' => 'employer socso', 'employer_eis' => 'employer eis',
    ];

    /** Words in Malay names that the listing and the staff record often disagree on. */
    private const array NAME_FILLER = ['bin', 'binti', 'bte', 'bt'];

    /**
     * @param  Collection<int, Employee>  $employees  everyone the rows may name
     * @return array{figures: array<int, array{columns: array<string, float>, ea: array<string, float>}>, skipped: list<string>, errors: list<string>}
     */
    public function read(UploadedFile $file, Collection $employees): array
    {
        [$handle, $col, $error] = CsvImport::open($file);
        if ($error !== null) {
            return ['figures' => [], 'skipped' => [], 'errors' => [$error]];
        }

        $missing = array_values(array_filter(self::COLUMNS, fn ($h) => ! isset($col[$h])));
        $nameCol = collect($col)->search(fn ($i, $h) => $h !== 'staff id' && ! in_array($h, self::COLUMNS, true));
        if ($missing !== [] || $nameCol === false) {
            fclose($handle);

            return ['figures' => [], 'skipped' => [], 'errors' => ['This is not the salary listing Summary tab. Missing column(s): '
                .implode(', ', array_map('strtoupper', $missing ?: ['staff name'])).'.']];
        }

        $byStaffId = $employees->filter(fn (Employee $e) => filled($e->staff_id))->keyBy(fn (Employee $e) => CsvImport::key($e->staff_id));
        $byName = $employees->groupBy(fn (Employee $e) => $this->nameKey($e->name));

        $figures = [];
        $skipped = [];
        $errors = [];
        $line = 1;
        while (($data = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $line++;
            if ($line > CsvImport::ROW_CAP + 1) {
                $errors[] = 'Stopped at '.CsvImport::ROW_CAP.' rows.';
                break;
            }
            $name = trim((string) ($data[$col[$nameCol]] ?? ''));
            // Blank lines, section labels (INTERN) and the TOTAL line carry nobody's pay.
            if ($name === '' || in_array(mb_strtolower($name), ['total', 'jumlah'], true) || CsvImport::cell($data, $col, 'basic') === '') {
                continue;
            }

            $staffId = CsvImport::key(CsvImport::cell($data, $col, 'staff id'));
            $matches = $staffId !== '' ? collect([$byStaffId->get($staffId)])->filter() : $byName->get($this->nameKey($name), collect());
            if ($matches->isEmpty()) {
                $skipped[] = $name;

                continue;
            }
            if ($matches->count() > 1) {
                $errors[] = "Row $line: more than one staff member is called $name. Add a STAFF ID column.";

                continue;
            }

            $v = [];
            foreach (self::COLUMNS as $field => $header) {
                $raw = CsvImport::cell($data, $col, $header);
                $v[$field] = $this->money($raw);
                if ($v[$field] === null) {
                    $errors[] = "Row $line ($name): '$raw' under ".strtoupper($header).' is not a number.';

                    continue 2;
                }
            }

            $parts = $v['basic'] + $v['bonus'] + $v['allowance'] + $v['medical'] + $v['mileage'] + $v['others'];
            if (abs($parts - $v['gross']) > 0.005) {
                $errors[] = "Row $line ($name): BASIC + BONUS + ALLOWANCE + MEDICAL + MILEAGE + OTHERS = "
                    .number_format($parts, 2).' but GROSS is '.number_format($v['gross'], 2).'.';

                continue;
            }
            if ($v['unpaid'] > $v['basic']) {
                $errors[] = "Row $line ($name): UNPAID LEAVE is more than BASIC.";

                continue;
            }

            // Someone on the listing twice (paid as an intern, then as staff) gets both rows.
            $id = $matches->first()->id;
            $figures[$id] = [
                'columns' => $this->add($figures[$id]['columns'] ?? [], [
                    'gross' => $v['basic'] - $v['unpaid'], 'additional_gross' => $v['bonus'], 'epf' => $v['epf'],
                    'pcb_paid' => $v['tax'], 'zakat_paid' => $v['zakat'], 'socso' => $v['socso'], 'eis' => $v['eis'],
                    'medical_claimed' => $v['medical'],
                ]),
                'ea' => $this->add($figures[$id]['ea'] ?? [], [
                    'b1c' => $v['allowance'], 'd2' => $v['cp38'],
                    'employer_epf' => $v['employer_epf'], 'employer_socso' => $v['employer_socso'], 'employer_eis' => $v['employer_eis'],
                ]),
            ];
        }
        fclose($handle);

        return ['figures' => $figures, 'skipped' => array_values(array_unique($skipped)), 'errors' => $errors];
    }

    /**
     * @param  array<string, float>  $into
     * @param  array<string, float>  $amounts
     * @return array<string, float>
     */
    private function add(array $into, array $amounts): array
    {
        foreach ($amounts as $key => $amount) {
            $into[$key] = round(($into[$key] ?? 0) + $amount, 2);
        }

        return $into;
    }

    /** A listing amount: "1,234.50", " 50,011.20 ", blank or an accounting "-" (zero). Null when it is not a number. */
    private function money(string $raw): ?float
    {
        $clean = str_replace([',', ' '], '', $raw);
        if ($clean === '' || $clean === '-') {
            return 0.0;
        }

        return is_numeric($clean) ? round((float) $clean, 2) : null;
    }

    /** Lower-case name without punctuation, extra spaces or bin/binti, so "AINA  AHMAD" finds "Aina Binti Ahmad". */
    private function nameKey(?string $name): string
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower((string) $name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return implode(' ', array_diff($words, self::NAME_FILLER));
    }
}
