<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
            'bonus' => 'float',
            'unpaid_days' => 'float',
            'unpaid_deduction' => 'float',
            'gross' => 'float',
            'epf_employee' => 'float',
            'epf_employer' => 'float',
            'socso_employee' => 'float',
            'socso_employer' => 'float',
            'eis_employee' => 'float',
            'eis_employer' => 'float',
            'pcb' => 'float',
            'claims_reimbursement' => 'float',
            'total_deductions' => 'float',
            'net_pay' => 'float',
            'employer_cost' => 'float',
        ];
    }

    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** Total employee-side statutory contributions. */
    public function statutoryEmployee(): float
    {
        return $this->epf_employee + $this->socso_employee + $this->eis_employee;
    }
}
