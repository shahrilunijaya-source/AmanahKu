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
 *
 * Most of this table is LHDN's Form TP3 in database form. `previous_employer` and
 * `previous_employer_tin` are TP3 section A; `optional_deductions` is section D's ∑LP.
 * `socso` and `eis` are NOT on Form TP3 — they come from the client's previous HRMS
 * take-on screen and are held only for the year-end EA form and HR reconciliation.
 *
 * Feeds PcbYearToDate → PcbCalculator (the LHDN P formula): gross, epf, pcb_paid,
 * zakat_paid, additional_gross, additional_epf, optional_deductions.
 * Record-keeping only, NEVER wired into the tax maths: socso, eis, previous_employer,
 * previous_employer_tin, exempt_allowances (see its own ponytail comment below).
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
        'socso',
        'eis',
        'previous_employer',
        'previous_employer_tin',
        'optional_deductions',
        'exempt_allowances',
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
            'socso' => 'float',
            'eis' => 'float',
            'optional_deductions' => 'float',
            // ponytail: exempt_allowances (TP3 section C2) is recorded for the EA form
            // only. It is not wired into PcbYearToDate/PcbCalculator because our own
            // monthly pay items have no exempt/non-exempt flag yet — applying it just to
            // opening figures while current-year pay is always treated as taxable would
            // be inconsistent. Wire it in once the pay-item catalogue can mark items exempt.
            'exempt_allowances' => 'float',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
