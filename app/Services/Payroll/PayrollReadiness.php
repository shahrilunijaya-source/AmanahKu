<?php

declare(strict_types=1);

namespace App\Services\Payroll;

use App\Models\Employee;
use App\Models\Tenant;
use App\Services\FeatureManager;

/**
 * Spec F2 readiness gate: what must be on file before a payroll run can be created.
 * A payslip with a blank identifier is a rejected agency upload on the 15th, so every
 * item here blocks except the TIN (LHDN accepts the NRIC in CP39 for staff without one).
 */
final class PayrollReadiness
{
    public function __construct(private readonly FeatureManager $features) {}

    /** @return list<string> */
    public function employerGaps(Tenant $tenant): array
    {
        $gaps = [];
        if (blank($tenant->epf_employer_no)) {
            $gaps[] = 'EPF employer number';
        }
        if (blank($tenant->socso_employer_code)) {
            $gaps[] = 'SOCSO employer code';
        }
        if (blank($tenant->employer_tin)) {
            $gaps[] = "Employer's TIN (E number)";
        }
        // HRD Corp number is only needed once the levy is switched on (spec F7).
        $hrdf = (string) ($this->features->value($tenant, 'payroll.hrdf') ?? 'off');
        if ($hrdf !== 'off' && blank($tenant->hrdf_registration_no)) {
            $gaps[] = 'HRD Corp registration number';
        }

        return $gaps;
    }

    /**
     * Non-blocking company-level notes. PSMB Act 2001: an employer in a covered industry
     * with 10 or more Malaysian employees must register with HRD Corp, so a tenant running
     * with the levy switched off past that headcount gets told once, in amber.
     *
     * @return list<string>
     */
    public function companyWarnings(Tenant $tenant): array
    {
        $hrdf = (string) ($this->features->value($tenant, 'payroll.hrdf') ?? 'off');
        if ($hrdf !== 'off') {
            return [];
        }
        $malaysians = Employee::active()->where('tenant_id', $tenant->id)
            ->whereIn('status', ['active', 'probation', 'on_leave'])
            ->whereHas('salaryStructure', fn ($q) => $q->where('nationality', 'citizen'))
            ->count();
        if ($malaysians < 10) {
            return [];
        }

        return ["HRD Corp levy is off but the company has {$malaysians} Malaysian employees; registration is mandatory at 10."];
    }

    /** @return list<array{employee: Employee, blocking: list<string>, warnings: list<string>}> */
    public function employeeRows(Tenant $tenant): array
    {
        return Employee::active()->where('tenant_id', $tenant->id)
            ->whereIn('status', ['active', 'probation', 'on_leave'])
            ->with('salaryStructure')->orderBy('name')->get()
            ->map(fn (Employee $e) => ['employee' => $e, ...$this->gapsFor($e)])
            ->values()->all();
    }

    /**
     * @param  list<int>  $excludedIds
     * @return list<array{employee: Employee, blocking: list<string>, warnings: list<string>}>
     */
    public function blockingRows(Tenant $tenant, array $excludedIds = []): array
    {
        return array_values(array_filter(
            $this->employeeRows($tenant),
            fn (array $row) => $row['blocking'] !== [] && ! in_array($row['employee']->id, $excludedIds, true),
        ));
    }

    /** @return array{blocking: list<string>, warnings: list<string>} */
    private function gapsFor(Employee $e): array
    {
        $s = $e->salaryStructure;
        if ($s === null) {
            return ['blocking' => ['Salary structure'], 'warnings' => []];
        }
        $blocking = [];
        if ((float) ($e->salary ?? 0) <= 0) {
            $blocking[] = 'Basic pay';
        }
        if (blank($e->nric)) {
            $blocking[] = 'NRIC';
        }
        if (blank($s->epf_no)) {
            $blocking[] = 'EPF number';
        }
        if (! $s->socso_exempt && blank($s->socso_no)) {
            $blocking[] = 'SOCSO number';
        }
        if (blank($s->bank_code) || blank($s->bank_account_no)) {
            $blocking[] = 'Bank';
        }
        if ($e->date_of_birth === null) {
            $blocking[] = 'Date of birth';
        }
        if ($e->joined_at === null) {
            $blocking[] = 'Joined date';
        }
        $warnings = blank($s->tax_no) ? ['TIN'] : [];

        return ['blocking' => $blocking, 'warnings' => $warnings];
    }
}
