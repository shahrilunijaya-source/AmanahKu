<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Spec F8: one line of an employee's Form TP1 declaration for one month. `amount` is an
 * optional deduction under App\Support\Tp1Reliefs (trimmed to that relief's yearly cap
 * when it reaches the PCB formula); `zakat_amount` is zakat the employee paid directly
 * to Pusat Zakat, which per the LHDN spec (p.35) must never appear on the payslip or
 * the EA form but is treated as this month's zakat for MTD.
 */
class PayrollTp1Claim extends Model
{
    use BelongsToTenant;

    /** @var list<string> */
    protected $fillable = [
        'employee_id',
        'year',
        'month',
        'relief_code',
        'amount',
        'zakat_amount',
        'note',
        'created_by_id',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'amount' => 'float',
            'zakat_amount' => 'float',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
