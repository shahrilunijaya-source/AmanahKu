<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\EmployeeFamilyMember;
use App\Models\PayrollFormOverride;
use App\Models\PayrollOpeningFigure;
use App\Models\PayrollSubmission;
use App\Models\Payslip;
use App\Models\Tenant;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Payroll → Form's LHDN staff forms, laid out item by item as the official forms number
 * them: CP21 (Pin.1/2025), CP22 (Pin.1/2021), CP22A (Pin.1/2023) and PCB 2(II) (Pin.2012),
 * all kept in docs/statutory. Every value is filled from the records and can be typed
 * over through the Edit button; a typed value (PayrollFormOverride) always wins.
 *
 * These forms are filed online on MyTax now, so the screen is for records and for
 * copying into MyTax field by field.
 */
final class StaffFormData
{
    public const array FORMS = ['cp21', 'cp22', 'cp22a', 'pcb2'];

    public const array TITLES = ['cp21' => 'LHDN CP21', 'cp22' => 'LHDN CP22', 'cp22a' => 'LHDN CP22A', 'pcb2' => 'LHDN PCB II Form'];

    private const array MONTHS = ['Januari', 'Februari', 'Mac', 'April', 'Mei', 'Jun', 'Julai', 'Ogos', 'September', 'Oktober', 'November', 'Disember'];

    public function __construct(private readonly EaFormData $ea) {}

    /**
     * Who each form lists for a year. Archived staff count: a leaver is usually archived
     * by the time HR prints their CP22A.
     *
     * @return Collection<int, Employee>
     */
    public function staff(Tenant $tenant, string $form, int $year): Collection
    {
        $query = Employee::withoutGlobalScopes()->where('tenant_id', $tenant->id)->orderBy('name');

        return match ($form) {
            'cp22' => $query->whereYear('joined_at', $year)->get(),
            'cp21', 'cp22a' => $query->whereYear('last_working_day', $year)->get(),
            // PCB II is the statement of tax deducted in the year, so anyone paid in it.
            default => $query->whereIn('id', $this->paidInYear($tenant, $year)->pluck('employee_id')->unique())->get(),
        };
    }

    /**
     * The form, section by section. Each field: key, the form's own item number, label,
     * value (typed-over value first), and how to draw it.
     *
     * @return array{sections: list<array{title: string, fields: list<array{key: string, no: string, label: string, value: ?string, kind: string, options?: list<string>}>}>, pcb?: array{rows: list<array{month: string, pcb: float, cp38: float, receipt: ?string, receipt_on: ?string}>, pcb_total: float, cp38_total: float}}
     */
    public function build(Tenant $tenant, Employee $employee, string $form, int $year, bool $withOverrides = true): array
    {
        $overrides = ! $withOverrides ? [] : PayrollFormOverride::withoutGlobalScopes()->where('tenant_id', $tenant->id)
            ->where('employee_id', $employee->id)->where('form', $form)->where('year', $year)->value('fields');
        $overrides = is_array($overrides) ? $overrides : (json_decode((string) $overrides, true) ?: []);

        $data = match ($form) {
            'cp21' => $this->cp21($tenant, $employee, $year),
            'cp22' => $this->cp22($tenant, $employee),
            'cp22a' => $this->cp22a($tenant, $employee, $year),
            default => $this->pcb2($tenant, $employee, $year),
        };

        foreach ($data['sections'] as &$section) {
            foreach ($section['fields'] as &$field) {
                if (array_key_exists($field['key'], $overrides)) {
                    $field['value'] = $overrides[$field['key']] === '' ? null : (string) $overrides[$field['key']];
                }
            }
        }
        unset($section, $field);

        return $data;
    }

    private function cp22a(Tenant $tenant, Employee $e, int $year): array
    {
        $ea = $this->ea->forEmployee($tenant, $e, $year);
        $spouse = $this->spouse($e);
        $s = $e->salaryStructure;

        return ['sections' => [
            $this->employerBlock($tenant, true),
            ['title' => 'A. Butir-butir pekerja yang berhenti kerja / bersara / meninggal dunia', 'fields' => [
                $this->f('a1', '1', 'Nama penuh', $this->fullName($e)),
                $this->f('a1b', '1(b)', 'No. Pengenalan (No. Kad Pengenalan / Pasport)', $this->ic($e), 'boxes'),
                $this->f('a1c', '1(c)', 'No. Pengenalan Cukai (TIN)', $s?->tax_no, 'boxes'),
                $this->f('a2', '2', 'Jenis pemberhentian', 'Berhenti kerja', 'choice', ['Berhenti kerja', 'Bersara', 'Meninggal dunia']),
                $this->f('a3', '3', 'Tarikh mula bekerja', $this->date($e->joined_at), 'date'),
                $this->f('a4', '4', 'Tarikh berhenti / persaraan / kematian', $this->date($e->last_working_day), 'date'),
                $this->f('a5', '5', 'Tarikh majikan terima pemakluman kematian pekerja', null, 'date'),
                $this->f('a6', '6', 'Jenis persaraan', null, 'choice', ['Wajib', 'Pilihan']),
                $this->f('a7', '7', 'Cukai ditanggung majikan', 'Tidak', 'choice', ['Ya', 'Tidak']),
                $this->f('a8', '8', 'Menerima tawaran skim pemberhentian pekerja', 'Tidak / Tidak berkenaan', 'choice', ['Ya', 'Tidak / Tidak berkenaan']),
                $this->f('a11', '11', 'Tarikh lahir', $this->date($e->date_of_birth), 'date'),
                $this->f('a12', '12', 'Taraf perkahwinan', $this->marital($e)),
                $this->f('a13a', '13(a)', 'Tuntutan potongan cukai bagi anak: bilangan anak', $s !== null && $s->children_relief_count ? (string) $s->children_relief_count : null),
                $this->f('a13b', '13(b)', 'Tuntutan potongan cukai bagi anak: jumlah (RM)', null, 'money'),
                $this->f('a14a', '14(a)', 'Nama penuh suami / isteri', $spouse?->name),
                $this->f('a14b', '14(b)', 'No. Pengenalan suami / isteri', $this->digits($spouse?->nric), 'boxes'),
                $this->f('a15', '15', 'No. telefon pekerja', $e->phone),
                $this->f('a16a', '16(a)', 'Alamat surat-menyurat terkini', $this->address($e), 'area'),
                $this->f('a16b', '16(b)', 'Alamat e-mel', $this->email($e)),
                $this->f('a17', '17', 'Maklumat wakil sah (bagi kes meninggal dunia): nama, no. pengenalan, hubungan, alamat, telefon', null, 'area'),
            ]],
            $this->remuneration('B. Butir-butir saraan', $ea, $e, $year, true),
            $this->unreported(),
            $this->otherParticulars($ea),
            $this->declaration($tenant, 'E. Akuan pegawai yang diberi kuasa'),
        ]];
    }

    private function cp21(Tenant $tenant, Employee $e, int $year): array
    {
        $ea = $this->ea->forEmployee($tenant, $e, $year);
        $s = $e->salaryStructure;

        return ['sections' => [
            $this->employerBlock($tenant, true),
            ['title' => 'A. Butir-butir pekerja yang akan meninggalkan Malaysia / Particulars of employee who will be leaving Malaysia', 'fields' => [
                $this->f('a1', '1', 'Nama penuh / Full name', $this->fullName($e)),
                $this->f('a2', '2', 'Tarikh mula bekerja / Date of commencement of employment', $this->date($e->joined_at), 'date'),
                $this->f('a3', '3', 'Tarikh dijangka meninggalkan Malaysia / Expected date to leave Malaysia', $this->date($e->last_working_day), 'date'),
                $this->f('a4', '4', 'Mastautin dalam tahun meninggalkan Malaysia / Resident in the year leaving Malaysia', null, 'choice', ['Ya / Yes', 'Tidak / No']),
                $this->f('a5', '5', 'No. Pengenalan (No. Kad Pengenalan / Pasport) / Identification no.', $this->ic($e), 'boxes'),
                $this->f('a6', '6', 'No. Pengenalan Cukai / Tax Identification No.', $s?->tax_no, 'boxes'),
                $this->f('a7', '7', 'Warganegara / Citizen', $e->nationality),
                $this->f('a8', '8', 'Tarikh lahir / Date of birth', $this->date($e->date_of_birth), 'date'),
                $this->f('a9', '9', 'Tempat lahir / Place of birth', null),
                $this->f('a10', '10', 'Jenis pekerjaan / Nature of employment', $e->position),
                $this->f('a11', '11', 'No. telefon pekerja / Employee\'s telephone no.', $e->phone),
                $this->f('a12', '12', 'Alamat e-mel / E-mail address', $this->email($e)),
                $this->f('a13', '13', 'Alamat surat-menyurat pekerja yang terkini / Current address of employee', $this->address($e), 'area'),
                $this->f('a14', '14', 'Alasan meninggalkan negara ini / Reason for departure', null, 'area'),
                $this->f('a15', '15', 'Alamat surat-menyurat di luar Malaysia / Correspondence address outside Malaysia', null, 'area'),
                $this->f('a16', '16', 'Tarikh dijangka kembali ke Malaysia / Expected date of return', null, 'date'),
                $this->f('a17', '17', 'Cukai ditanggung majikan / Tax borne by employer', 'Tidak / No', 'choice', ['Ya / Yes', 'Tidak / No']),
                $this->f('a18', '18', 'Menerima tawaran skim pemberhentian pekerja / Accepted offer under employee separation scheme', 'Tidak / No', 'choice', ['Ya / Yes', 'Tidak / No']),
            ]],
            $this->remuneration('B. Butir-butir saraan / Particulars of remuneration', $ea, $e, $year, false),
            $this->unreported(),
            $this->otherParticulars($ea),
            $this->declaration($tenant, 'E. Akuan pegawai yang diberi kuasa / Declaration by authorised officer'),
        ]];
    }

    private function cp22(Tenant $tenant, Employee $e): array
    {
        $spouse = $this->spouse($e);
        $s = $e->salaryStructure;
        $salary = $e->salary !== null ? (float) $e->salary : null;

        return ['sections' => [
            ['title' => 'A. Maklumat majikan / Particulars of employer', 'fields' => [
                $this->f('emp_name', 'A1', 'Nama majikan / Employer\'s name', $tenant->name),
                $this->f('emp_address', 'A2', 'Alamat majikan / Employer\'s address', $tenant->address, 'area'),
                $this->f('emp_no', 'A3', 'No. majikan / Employer\'s no. (E)', $this->digits($tenant->employer_tin), 'boxes'),
                $this->f('emp_email', 'A4', 'e-Mel / e-Mail', $tenant->email),
                $this->f('emp_phone', 'A5', 'No. telefon / Telephone no.', $tenant->contact_number),
            ]],
            ['title' => 'B. Maklumat pekerja baharu / Particulars of new employee', 'fields' => [
                $this->f('b1', 'B1', 'Nama penuh / Full name', $this->fullName($e)),
                $this->f('b2', 'B2', 'No. cukai pendapatan / Income tax no.', $s?->tax_no, 'boxes'),
                $this->f('b3', 'B3', 'No. pengenalan / Identification no.', $this->digits($e->nric), 'boxes'),
                $this->f('b4', 'B4', 'No. pasport semasa / Current passport no.', $e->passport_no),
                $this->f('b5', 'B5', 'No. pasport didaftar dengan LHDNM / Passport no. registered with IRBM', null),
                $this->f('b6', 'B6', 'Warganegara / Citizen', $e->nationality),
                $this->f('b7', 'B7', 'Jantina / Gender', $e->gender),
                $this->f('b8', 'B8', 'Tarikh lahir / Date of birth', $this->date($e->date_of_birth), 'date'),
                $this->f('b9', 'B9', 'Status perkahwinan / Marital status', $this->marital($e)),
                $this->f('b10', 'B10', 'No. telefon / Telephone no.', $e->phone),
                $this->f('b11', 'B11', 'e-Mel / e-Mail', $this->email($e)),
                $this->f('b12', 'B12', 'Alamat kediaman yang terkini / Current residential address', $this->address($e), 'area'),
                $this->f('b13', 'B13', 'Alamat surat-menyurat yang terkini / Current correspondence address', $this->address($e), 'area'),
                $this->f('b14', 'B14', 'Tarikh permulaan pekerjaan semasa / Commencement date of current employment', $this->date($e->joined_at), 'date'),
                $this->f('b15', 'B15', 'Jawatan / Designation', $e->position),
                $this->f('b16', 'B16', 'Tempoh pekerjaan yang dijangkakan / Expected duration of employment', null),
                $this->f('b17', 'B17', 'Jenis pekerjaan / Nature of employment', $e->employmentType?->name),
            ]],
            ['title' => 'C. Maklumat suami / isteri (jika berkahwin) / Particulars of husband / wife (if married)', 'fields' => [
                $this->f('c1', 'C1', 'Nama penuh suami / isteri / Full name of husband / wife', $spouse?->name),
                $this->f('c2', 'C2', 'No. pengenalan / pasport / Identification / passport no.', $this->digits($spouse?->nric), 'boxes'),
                $this->f('c3', 'C3', 'No. cukai pendapatan / Income tax no.', null, 'boxes'),
                $this->f('c4', 'C4', 'No. telefon / Telephone no.', $spouse?->phone),
            ]],
            ['title' => 'D. Maklumat saraan bulanan / Particulars of monthly remuneration', 'fields' => [
                $this->f('d1', 'D1', 'Gaji, bayaran, upah dan kerja lebih masa / Salary, fees, wages and overtime pay', $this->money($salary), 'money'),
                $this->f('d2', 'D2', 'Gaji cuti / Leave pay', null, 'money'),
                $this->f('d3', 'D3', 'Komisen dan bonus / Commission and bonus', null, 'money'),
                $this->f('d4', 'D4', 'Elaun tunai termasuk cukai ditanggung oleh majikan / Cash allowances including tax borne by the employer', null, 'money'),
                $this->f('d5', 'D5', 'Manfaat berupa barangan yang layak dikenakan cukai / Benefits in kind subject to tax', null, 'money'),
                $this->f('d6', 'D6', 'Nilai tempat kediaman yang disediakan majikan / Value of living accommodation', null, 'money'),
                $this->f('d7', 'D7', 'Elaun-elaun selain daripada wang / Allowances in kind', null, 'money'),
                $this->f('d8', 'D8', 'Bayaran-bayaran lain / Other payments', null, 'money'),
                $this->f('d_total', '', 'Jumlah / Total (RM)', $this->money($salary), 'money'),
            ]],
            ['title' => 'E. Maklumat majikan terdahulu di Malaysia / Particulars of previous employer in Malaysia', 'fields' => [
                $this->f('e1', 'E1', 'Nama majikan / Employer\'s name', PayrollOpeningFigure::withoutGlobalScopes()
                    ->where('employee_id', $e->id)->where('year', $e->joined_at?->year)->value('previous_employer')),
                $this->f('e2', 'E2', 'Alamat majikan / Employer\'s address', null, 'area'),
            ]],
            $this->declaration($tenant, 'F. Akuan pegawai yang diberi kuasa / Declaration by authorised officer', true),
        ]];
    }

    /**
     * PCB 2(II): the employer's statement of tax deducted in the year, month by month,
     * with the receipt number and date of each payment from the PCB filing record.
     */
    private function pcb2(Tenant $tenant, Employee $e, int $year): array
    {
        $slips = $this->paidInYear($tenant, $year)->where('employee_id', $e->id);
        $receipts = PayrollSubmission::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('agency', 'pcb')
            ->whereNotNull('submitted_at')->with('payrollRun')->get()
            ->filter(fn (PayrollSubmission $s) => str_starts_with((string) $s->payrollRun?->period, $year.'-'))
            ->keyBy(fn (PayrollSubmission $s) => (int) substr((string) $s->payrollRun?->period, 5, 2));

        $rows = [];
        foreach (self::MONTHS as $i => $name) {
            $month = $slips->filter(fn (Payslip $p) => (int) substr((string) $p->payrollRun?->period, 5, 2) === $i + 1);
            $pcb = round((float) $month->sum(fn (Payslip $p) => (float) $p->pcb + (float) $p->pcb_additional), 2);
            $cp38 = round((float) $month->sum('cp38'), 2);
            $receipt = ($pcb + $cp38) > 0 ? $receipts->get($i + 1) : null;
            $rows[] = ['month' => $name, 'pcb' => $pcb, 'cp38' => $cp38,
                'receipt' => $receipt?->receipt_reference, 'receipt_on' => $this->date($receipt?->submitted_at)];
        }
        $signatory = $tenant->statutorySignatory;

        return [
            'sections' => [
                ['title' => 'Penyata bayaran cukai oleh majikan', 'fields' => [
                    $this->f('date', '', 'Tarikh', $this->date(now()), 'date'),
                    $this->f('branch', '', 'Cawangan LHDNM', null),
                    $this->f('year', '', 'Potongan cukai yang dibuat dalam tahun', (string) $year),
                    $this->f('name', '', 'Nama pekerja', $this->fullName($e)),
                    $this->f('ic', '', 'No. Kad Pengenalan / No. Pasport', $this->ic($e)),
                    $this->f('tax_no', '', 'No. Cukai Pendapatan Pekerja', $e->salaryStructure?->tax_no),
                    $this->f('staff_no', '', 'No. Pekerja', $e->staff_id),
                    $this->f('emp_no', '', 'No. Majikan (E)', $this->digits($tenant->employer_tin)),
                ]],
                ['title' => 'Pegawai', 'fields' => [
                    $this->f('officer', '', 'Nama pegawai', $signatory?->name),
                    $this->f('officer_role', '', 'Jawatan', $signatory?->position),
                    $this->f('officer_phone', '', 'No. Telefon', $tenant->payroll_contact_phone ?: $tenant->contact_number),
                    $this->f('employer', '', 'Nama dan alamat majikan', trim($tenant->name."\n".$tenant->address), 'area'),
                ]],
            ],
            'pcb' => ['rows' => $rows, 'pcb_total' => round(array_sum(array_column($rows, 'pcb')), 2), 'cp38_total' => round(array_sum(array_column($rows, 'cp38')), 2)],
        ];
    }

    /** Employer name, address, E number and phone, as boxed at the top of CP21 and CP22A. */
    private function employerBlock(Tenant $tenant, bool $withStatus): array
    {
        $fields = [
            $this->f('emp_name', '', 'Nama & alamat majikan', trim($tenant->name."\n".$tenant->address), 'area'),
            $this->f('emp_no', '', 'No. majikan (E)', $this->digits($tenant->employer_tin), 'boxes'),
            $this->f('emp_phone', '', 'No. telefon majikan', $tenant->contact_number),
        ];
        if ($withStatus) {
            $fields[] = $this->f('status', '', 'Status pemberitahuan', 'Baharu', 'choice', ['Baharu', 'Pindaan', 'Tambahan']);
        }

        return ['title' => '', 'fields' => $fields];
    }

    /**
     * Part B of CP21 and CP22A: the year's pay to the last day, bucketed from the Form EA
     * boxes. CP22A has a 13th line (share schemes) CP21 doesn't.
     */
    private function remuneration(string $title, array $ea, Employee $e, int $year, bool $withShares): array
    {
        $box = fn (string ...$keys) => array_sum(array_map(fn (string $k) => (float) ($ea['employment_income']['by_category'][$k] ?? 0), $keys));
        $lines = [
            ['b1', '1', 'Gaji, bayaran, upah dan kerja lebih masa', $box('B1(a)', 'unclassified')],
            ['b2', '2', 'Gaji cuti', 0.0],
            ['b3', '3', 'Komisen dan bonus', $box('B1(b)')],
            ['b4', '4', 'Ganjaran', $box('B1(f)')],
            ['b5', '5', 'Pampasan kerana kehilangan pekerjaan', $box('B6')],
            ['b6', '6', 'Elaun tunai termasuk cukai ditanggung oleh majikan', $box('B1(c)', 'B1(d)')],
            ['b7', '7', 'Pencen daripada majikan', $box('C1', 'C2')],
            ['b8', '8', 'Manfaat berupa barangan yang layak dikenakan cukai', $box('B3')],
            ['b9', '9', 'Nilai tempat kediaman yang disediakan majikan', $box('B4')],
            ['b10', '10', 'Elaun-elaun selain daripada wang seperti makanan, pakaian, lojing atau pembantu rumah', 0.0],
            ['b11', '11', $withShares ? 'Kereta dan pemandu' : 'Lain-lain bayaran', $withShares ? 0.0 : $box('B1(e)', 'B2', 'B5')],
        ];
        if ($withShares) {
            $lines[] = ['b12', '12', 'Lain-lain bayaran', $box('B2', 'B5')];
            $lines[] = ['b13', '13', 'Manfaat dari Skim Pemberian Saham (ESOS, ESPP dan lain-lain)', $box('B1(e)')];
        }
        $from = $e->joined_at !== null && $e->joined_at->year === $year ? $e->joined_at : now()->setDate($year, 1, 1);

        $fields = [
            $this->f('b_from', '', 'Tempoh dari', $this->date($from), 'date'),
            $this->f('b_to', '', 'Tempoh hingga', $this->date($e->last_working_day), 'date'),
        ];
        foreach ($lines as [$key, $no, $label, $amount]) {
            $fields[] = $this->f($key, $no, $label, $amount > 0 ? $this->money($amount) : null, 'money');
        }
        $fields[] = $this->f('b_total', '', 'Jumlah (RM)', $this->money(array_sum(array_column($lines, 3))), 'money');

        return ['title' => $title, 'fields' => $fields];
    }

    /** Part C: income of earlier years not declared yet. Nothing on record knows this. */
    private function unreported(): array
    {
        return ['title' => 'C. Butir-butir pendapatan yang belum dilaporkan', 'fields' => [
            $this->f('c', '', 'Jenis pendapatan, tempoh diperoleh, jumlah pendapatan (RM), caruman KWSP pekerja (RM)', null, 'area'),
        ]];
    }

    private function otherParticulars(array $ea): array
    {
        $d = $ea['deductions'];

        return ['title' => 'D. Butir-butir lain', 'fields' => [
            $this->f('d1', '1', 'Jumlah wang yang ditahan oleh majikan dan akan dibayar kepada pekerja (RM)', null, 'money'),
            $this->f('d2', '2', 'Jumlah Potongan Cukai Bulanan yang dibayar ke LHDNM dalam tahun ini (RM)', $this->money($d['pcb_total']), 'money'),
            $this->f('d3', '3', 'Jumlah potongan zakat yang dibayar dalam tahun ini (RM)', $this->money($d['zakat']), 'money'),
            $this->f('d4', '4', 'Caruman pekerja kepada KWSP atau kumpulan wang yang diluluskan (RM)', $this->money($d['epf_employee']), 'money'),
        ]];
    }

    /** The authorised officer: Company Settings → Statutory & tax → Signatory. */
    private function declaration(Tenant $tenant, string $title, bool $withIc = false): array
    {
        $who = $tenant->statutorySignatory;
        $fields = [$this->f('sign_name', '', 'Nama / Name', $who?->name)];
        if ($withIc) {
            $fields[] = $this->f('sign_ic', '', 'No. pengenalan / pasport / Identification / passport no.', $this->digits($who?->nric));
        }
        $fields[] = $this->f('sign_role', '', 'Jawatan / Designation', $who?->position);
        if (! $withIc) {
            $fields[] = $this->f('sign_email', '', 'Alamat e-mel / E-mail address', $who !== null ? $this->email($who) : null);
        }
        $fields[] = $this->f('sign_date', '', 'Tarikh / Date', null, 'date');

        return ['title' => $title, 'fields' => $fields];
    }

    /** @return Collection<int, Payslip> */
    private function paidInYear(Tenant $tenant, int $year): Collection
    {
        return Payslip::withoutGlobalScopes()->where('tenant_id', $tenant->id)->with('payrollRun')
            ->whereHas('payrollRun', fn ($q) => $q->where('tenant_id', $tenant->id)->where('status', 'finalized')
                ->countsAsRemuneration()->where('period', 'like', $year.'-%'))
            ->get();
    }

    /**
     * @param  list<string>  $options
     * @return array{key: string, no: string, label: string, value: ?string, kind: string, options?: list<string>}
     */
    private function f(string $key, string $no, string $label, ?string $value, string $kind = 'text', array $options = []): array
    {
        $field = ['key' => $key, 'no' => $no, 'label' => $label, 'value' => $value === '' ? null : $value, 'kind' => $kind];
        if ($options !== []) {
            $field['options'] = $options;
        }

        return $field;
    }

    private function fullName(Employee $e): string
    {
        return (string) ($e->full_name_ic ?: $e->name);
    }

    /** NRIC as digits, or the passport number for someone without one. */
    private function ic(Employee $e): ?string
    {
        return $this->digits($e->nric) ?: $e->passport_no;
    }

    private function address(Employee $e): ?string
    {
        $lines = array_filter([$e->address, $e->address_2, trim($e->postcode.' '.$e->city), $e->state]);

        return $lines === [] ? null : implode("\n", $lines);
    }

    private function email(Employee $e): ?string
    {
        return $e->personal_email ?: $e->user?->email;
    }

    private function marital(Employee $e): ?string
    {
        return $e->marital_status !== null ? ucfirst((string) $e->marital_status) : null;
    }

    private function spouse(Employee $e): ?EmployeeFamilyMember
    {
        return EmployeeFamilyMember::withoutGlobalScopes()->where('employee_id', $e->id)->where('relation', 'spouse')->where('deceased', false)->first();
    }

    private function date(?CarbonInterface $date): ?string
    {
        return $date?->format('d-m-Y');
    }

    private function money(?float $amount): ?string
    {
        return $amount === null ? null : number_format($amount, 2, '.', '');
    }

    private function digits(?string $value): ?string
    {
        $digits = preg_replace('/[^0-9A-Za-z]/', '', (string) $value);

        return $digits === '' || $digits === null ? null : strtoupper($digits);
    }
}
