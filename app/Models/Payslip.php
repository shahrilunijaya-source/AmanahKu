<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * `additions`, `other_deductions`, `claim_ids`, `overtime_request_ids` and
 * `unpaid_leave_request_ids` have an `array` cast, so they read back as arrays (or
 * null). Without this, static analysis takes the raw json column type and reports a
 * flatMap()/foreach over them as always-empty (same reasoning as the date casts
 * documented on Employee).
 *
 * @property list<array{name?: string, amount?: float|int|string}>|null $additions
 * @property list<array{name?: string, amount?: float|int|string}>|null $other_deductions
 * @property list<int>|null $claim_ids
 * @property list<int>|null $overtime_request_ids
 * @property list<int>|null $unpaid_leave_request_ids
 */
class Payslip extends Model
{
    use BelongsToTenant;

    /**
     * Only the linkage columns are mass-assignable. Every earnings/statutory/total amount
     * is computed by PayrollCalculator and written with forceFill() from the controller, so
     * those columns are deliberately excluded here. tenant_id is set by BelongsToTenant.
     *
     * @var list<string>
     */
    protected $fillable = [
        'employee_id',
        'claim_ids',
    ];

    protected function casts(): array
    {
        return [
            'additions' => 'array',
            'other_deductions' => 'array',
            'claim_ids' => 'array',
            'basic' => 'float',
            'allowances_total' => 'float',
            'overtime_hours' => 'float',
            'overtime_amount' => 'float',
            'overtime_multiplier' => 'float',
            'overtime_request_ids' => 'array',
            'pulled_overtime_hours' => 'float',
            'overtime_overridden' => 'boolean',
            'bonus' => 'float',
            'unpaid_days' => 'float',
            'unpaid_deduction' => 'float',
            'unpaid_leave_request_ids' => 'array',
            'pulled_unpaid_days' => 'float',
            'unpaid_days_overridden' => 'boolean',
            'fixed_deductions_total' => 'float',
            'gross' => 'float',
            'epf_employee' => 'float',
            'epf_employer' => 'float',
            'socso_employee' => 'float',
            'socso_employer' => 'float',
            'eis_employee' => 'float',
            'eis_employer' => 'float',
            'skbbk_employee' => 'float',
            'pcb' => 'float',
            'pcb_additional' => 'float',
            'zakat' => 'float',
            'cp38' => 'float',
            'pcb_override' => 'float',
            'claims_reimbursement' => 'float',
            'total_deductions' => 'float',
            'net_pay' => 'float',
            'employer_cost' => 'float',
        ];
    }

    /** @return BelongsTo<PayrollRun, $this> */
    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class);
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Itemised catalogue lines, additive to the lumped columns above. Empty on payslips
     * issued before this feature — views must fall back to the lumped columns for those.
     *
     * @return HasMany<PayslipLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(PayslipLine::class)->orderBy('sort_order');
    }

    /** Total employee-side statutory contributions, including SKBBK. */
    public function statutoryEmployee(): float
    {
        return $this->epf_employee + $this->socso_employee + $this->eis_employee + $this->skbbk_employee;
    }
}
