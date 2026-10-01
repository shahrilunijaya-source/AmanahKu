<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalaryStructure extends Model
{
    use BelongsToTenant;

    /**
     * Operator-supplied salary inputs only. tenant_id is set by BelongsToTenant; there
     * are no controller-computed columns on this table, so the full input set is fillable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'employee_id',
        'basic_salary',
        'allowances',
        'effective_from',
        'bank_name',
        'bank_code',
        'bank_account_no',
        'epf_no',
        'socso_no',
        'nationality',
        // epf_opt_in_60plus is stored but read by no calculation.
        'epf_opt_in_60plus',
        'epf_employee_rate_override',
        'epf_employer_rate_override',
        'epf_additional_by',
        'epf_additional_employee',
        'epf_additional_employer',
        'tax_no',
        'spouse_working',
        'children_relief_count',
        'disabled_self',
        'disabled_spouse',
        'zakat_monthly',
        'zakat_authority',
        'cp38_monthly',
        'skbbk_opt_in',
        // Worksy Bank & Statutory tab (2026-09-28). Read by no calculation; reference data only.
        'bank_holder_name',
        'tax_resident',
        'tax_category',
        'employee_tax_status',
        'child_relief_breakdown',
        'epf_scheme',
        'socso_category',
        'socso_exempt',
        'hrdf_exempt',
    ];

    protected function casts(): array
    {
        return [
            'basic_salary' => 'float',
            'allowances' => 'array',
            'effective_from' => 'date',
            'epf_opt_in_60plus' => 'boolean',
            'epf_employee_rate_override' => 'float',
            'epf_employer_rate_override' => 'float',
            'epf_additional_employee' => 'float',
            'epf_additional_employer' => 'float',
            'spouse_working' => 'boolean',
            'children_relief_count' => 'integer',
            'disabled_self' => 'boolean',
            'disabled_spouse' => 'boolean',
            'zakat_monthly' => 'float',
            'cp38_monthly' => 'float',
            'tax_resident' => 'boolean',
            'child_relief_breakdown' => 'array',
            'skbbk_opt_in' => 'boolean',
            'socso_exempt' => 'boolean',
            'hrdf_exempt' => 'boolean',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * This person's EPF setup, in the shape EpfCalculator::contribution() takes.
     *
     * @return array{scheme: string|null, employee_rate: float|null, employer_rate: float|null, additional_by: string|null, additional_employee: float|null, additional_employer: float|null}
     */
    public function epfSetup(): array
    {
        return [
            'scheme' => $this->epf_scheme,
            'employee_rate' => $this->epf_employee_rate_override,
            'employer_rate' => $this->epf_employer_rate_override,
            'additional_by' => $this->epf_additional_by,
            'additional_employee' => $this->epf_additional_employee,
            'additional_employer' => $this->epf_additional_employer,
        ];
    }
}
