<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Spec F9: one LHDN CP38 direction. `monthly_instalment` is what the direction orders
 * per month; `remaining_balance` counts down as finalized payslips collect it and is
 * null for an open-ended direction (no stated total). See Cp38Notices for the maths.
 */
class PayrollCp38Notice extends Model
{
    use BelongsToTenant;

    /** @var list<string> */
    protected $fillable = [
        'employee_id',
        'reference',
        'notice_date',
        'total_amount',
        'monthly_instalment',
        'first_period',
        'last_period',
        'remaining_balance',
        'status',
        'created_by_id',
    ];

    protected function casts(): array
    {
        return [
            'notice_date' => 'date',
            'total_amount' => 'float',
            'monthly_instalment' => 'float',
            'remaining_balance' => 'float',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
