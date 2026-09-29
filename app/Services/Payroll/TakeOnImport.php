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
 * listing already has. Import is two steps: rows() turns the file into review rows that
 * HR checks on screen (pick the staff member for a name that did not match, fix a cell
 * marked wrong), then figures() turns the reviewed rows into Take On figures
 * (PayrollOpeningFigure):
 *
 * - B1(a) salary is BASIC less UNPAID LEAVE. The listing's GROSS is before unpaid leave,
 *   which it only takes off on the way to NET, but tax is on what was actually earned.
 * - MEDICAL, MILEAGE and OTHERS are claims paid back against receipts: not income, not
 *   on Form EA (same as this app's own claim-reimbursement line). MEDICAL is still kept,
 *   because it uses up the yearly medical claim cap.
 * - ADVANCE, DEDUCTION and NET SALARY only moved take-home pay, so they are not read.
 */
final class TakeOnImport
{
    /** @var array<string, string> field => listing header (matched lower-cased) */
    public const array COLUMNS = [
        'basic' => 'basic', 'bonus' => 'bonus', 'allowance' => 'allowance', 'medical' => 'medical',
        'mileage' => 'mileage', 'others' => 'others', 'gross' => 'gross', 'epf' => 'epf', 'tax' => 'tax',
        'socso' => 'socso', 'eis' => 'eis', 'zakat' => 'zakat', 'unpaid' => 'unpaid leave', 'cp38' => 'cp38',
        'employer_epf' => 'employer epf', 'employer_socso' => 'employer socso', 'employer_eis' => 'employer eis',
    ];

    /** Words in Malay names that the listing and the staff record often disagree on. */
    private const array NAME_FILLER = ['bin', 'binti', 'bte', 'bt'];

    /**
     * Every row that carries pay, matched to a staff member where the name (or a STAFF ID
     * column) finds exactly one. Blank lines, section labels (INTERN) and the TOTAL line
     * are left out. Nothing is checked or saved here.
     *
     * @param  Collection<int, Employee>  $employees  everyone the rows may name
     *                                                A row that finds nobody may still carry a `suggestion`: the one staff member whose name
     *                                                covers every word of the file's name, allowing one typo per longer word ("Ahmad Irfan"
     *                                                for Ahmad Irfan Bin Harman, "Muhibbuddin" for Muhibbudin). HR confirms it on screen;
     *                                                it is never imported on its own.
     * @return array{rows: list<array{line: int, name: string, employee_id: ?int, suggestion: ?int, ambiguous: bool, values: array<string, string>}>, error: ?string}
     */
    public function rows(UploadedFile $file, Collection $employees): array
    {
        [$handle, $col, $error] = CsvImport::open($file);
        if ($error !== null) {
            return ['rows' => [], 'error' => $error];
        }

        $missing = array_values(array_filter(self::COLUMNS, fn ($h) => ! isset($col[$h])));
        $nameCol = collect($col)->search(fn ($i, $h) => $h !== 'staff id' && ! in_array($h, self::COLUMNS, true));
        if ($missing !== [] || $nameCol === false) {
            fclose($handle);

            return ['rows' => [], 'error' => 'This is not the salary listing Summary tab. Missing column(s): '
                .implode(', ', array_map('strtoupper', $missing ?: ['staff name'])).'.'];
        }

        $byStaffId = $employees->filter(fn (Employee $e) => filled($e->staff_id))->keyBy(fn (Employee $e) => CsvImport::key($e->staff_id));
        $byName = $employees->groupBy(fn (Employee $e) => $this->nameKey($e->name));

        $rows = [];
        $line = 1;
        while (($data = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $line++;
            $name = trim((string) ($data[$col[$nameCol]] ?? ''));
            // Google Sheets also exports every empty grid row, so only rows with pay count toward the cap.
            if ($name === '' || in_array(mb_strtolower($name), ['total', 'jumlah'], true) || CsvImport::cell($data, $col, 'basic') === '') {
                continue;
            }
            if (count($rows) === CsvImport::ROW_CAP) {
                fclose($handle);

                return ['rows' => [], 'error' => 'The file has more than '.CsvImport::ROW_CAP.' staff rows.'];
            }

            $staffId = CsvImport::key(CsvImport::cell($data, $col, 'staff id'));
            $matches = $staffId !== '' ? collect([$byStaffId->get($staffId)])->filter() : $byName->get($this->nameKey($name), collect());
            $rows[] = [
                'line' => $line,
                'name' => $name,
                'employee_id' => $matches->count() === 1 ? $matches->first()->id : null,
                'suggestion' => $matches->isEmpty() && $staffId === '' ? $this->suggest($name, $employees) : null,
                'ambiguous' => $matches->count() > 1,
                'values' => collect(self::COLUMNS)->map(fn ($header) => CsvImport::cell($data, $col, $header))->all(),
            ];
        }
        fclose($handle);

        return ['rows' => $rows, 'error' => null];
    }

    /**
     * What is wrong with one row's amounts, keyed by the field to point at. Empty when fine.
     * The review screen runs the same checks as HR types; this is the one that decides.
     *
     * @param  array<string, mixed>  $values  field => amount as typed
     * @return array<string, string>
     */
    public function problems(array $values): array
    {
        $v = [];
        $problems = [];
        foreach (array_keys(self::COLUMNS) as $field) {
            $raw = is_scalar($values[$field] ?? null) ? (string) $values[$field] : '';
            $v[$field] = $this->money($raw);
            if ($v[$field] === null) {
                $problems[$field] = "'$raw' is not a number.";
            }
        }
        if ($problems !== []) {
            return $problems;
        }

        $parts = round($v['basic'] + $v['bonus'] + $v['allowance'] + $v['medical'] + $v['mileage'] + $v['others'], 2);
        if (abs($parts - $v['gross']) > 0.005) {
            $problems['gross'] = 'BASIC + BONUS + ALLOWANCE + MEDICAL + MILEAGE + OTHERS = '.number_format($parts, 2)
                .', but GROSS is '.number_format((float) $v['gross'], 2).' ('.number_format(abs($parts - $v['gross']), 2).' apart).';
        }
        if ($v['unpaid'] > $v['basic']) {
            $problems['unpaid'] = 'UNPAID LEAVE is more than BASIC.';
        }

        return $problems;
    }

    /**
     * Take On figures per employee. Someone on the listing twice (paid as an intern, then
     * as staff) gets both rows added together. Every row must already pass problems().
     *
     * @param  list<array{employee_id: int, values: array<string, mixed>}>  $rows
     * @return array<int, array{columns: array<string, float>, ea: array<string, float>}>
     */
    public function figures(array $rows): array
    {
        $figures = [];
        foreach ($rows as ['employee_id' => $id, 'values' => $values]) {
            $v = collect(self::COLUMNS)->map(fn ($h, $field) => $this->money((string) $values[$field]) ?? 0.0)->all();
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

        return $figures;
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

    /**
     * The one employee whose name contains every word of $name, each exactly or (for words of
     * five letters or more) one typo away. Null when nobody or more than one fits, or when
     * the file gives a single word, which is too little to go on.
     *
     * @param  Collection<int, Employee>  $employees
     */
    private function suggest(string $name, Collection $employees): ?int
    {
        $words = explode(' ', $this->nameKey($name));
        if (count($words) < 2) {
            return null;
        }
        $fits = $employees->filter(function (Employee $e) use ($words) {
            $theirs = explode(' ', $this->nameKey($e->name));

            return collect($words)->every(fn ($w) => collect($theirs)->contains(
                fn ($t) => $t === $w || (mb_strlen($w) >= 5 && levenshtein($t, $w) <= 1)));
        });

        return $fits->count() === 1 ? $fits->first()->id : null;
    }

    /** Lower-case name without punctuation, extra spaces or bin/binti, so "AINA  AHMAD" finds "Aina Binti Ahmad". */
    private function nameKey(?string $name): string
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower((string) $name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return implode(' ', array_diff($words, self::NAME_FILLER));
    }
}
