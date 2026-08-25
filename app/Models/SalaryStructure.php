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
        'bank_account_no',
        'epf_no',
        'socso_no',
        'nric',
        'nationality',
        'epf_opt_in_60plus',
        'epf_employee_rate_override',
        'tax_no',
        'marital_status',
        'spouse_working',
        'children_relief_count',
        'disabled_self',
        'disabled_spouse',
        'zakat_monthly',
        'cp38_monthly',
        'skbbk_opt_in',
    ];

    protected function casts(): array
    {
        return [
            'basic_salary' => 'float',
            'allowances' => 'array',
            'effective_from' => 'date',
            'nric' => 'encrypted',   // PII at rest (I-018); statutory reports decrypt on read
            'epf_opt_in_60plus' => 'boolean',
            'epf_employee_rate_override' => 'float',
            'spouse_working' => 'boolean',
            'children_relief_count' => 'integer',
            'disabled_self' => 'boolean',
            'disabled_spouse' => 'boolean',
            'zakat_monthly' => 'float',
            'cp38_monthly' => 'float',
            'skbbk_opt_in' => 'boolean',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** Sum of all fixed allowance lines. */
    public function allowancesTotal(): float
    {
        return collect($this->allowances ?? [])->sum(fn ($a) => (float) ($a['amount'] ?? 0));
    }
}
