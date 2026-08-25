<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "Payroll Figures Take On" — what a previous employer or previous payroll system
 * already paid an employee earlier in a calendar year. One row per tenant/employee/
 * year. Without this, anyone who joins mid-year (or any company switching to this app
 * mid-year) gets a wrong PCB for the rest of the year and a wrong EA form, because
 * PcbYearToDate has no way to see pay that happened outside this app.
 */
class PayrollOpeningFigure extends Model
{
    use BelongsToTenant;

    /** @var list<string> */
    protected $fillable = [
        'employee_id',
        'year',
        'gross',
        'epf',
        'pcb_paid',
        'zakat_paid',
        'additional_gross',
        'additional_epf',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'gross' => 'float',
            'epf' => 'float',
            'pcb_paid' => 'float',
            'zakat_paid' => 'float',
            'additional_gross' => 'float',
            'additional_epf' => 'float',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
