<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Spec F12: one agency filing (EPF, SOCSO/EIS, PCB, HRD Corp monthly; Form EA and
 * Form E yearly). See App\Services\Payroll\StatutoryCalendar for the due dates.
 *
 * @property Carbon|null $due_on
 * @property Carbon|null $downloaded_at
 * @property Carbon|null $submitted_at
 * @property-read PayrollRun|null $payrollRun
 */
class PayrollSubmission extends Model
{
    use BelongsToTenant;

    public const array AGENCIES = ['epf', 'socso_eis', 'pcb', 'hrdcorp', 'ea', 'form_e'];

    /** Agency labels, English then Malay, for the Deadlines tab and the digest. */
    public const array LABELS = [
        'epf' => ['EPF (KWSP Form A)', 'KWSP (Borang A)'],
        'socso_eis' => ['SOCSO and EIS (Form 8A)', 'PERKESO dan SIP (Borang 8A)'],
        'pcb' => ['PCB (CP39)', 'PCB (CP39)'],
        'hrdcorp' => ['HRD Corp levy', 'Levi HRD Corp'],
        'ea' => ['Form EA to employees', 'Borang EA kepada pekerja'],
        'form_e' => ['Form E to LHDN', 'Borang E kepada LHDN'],
    ];

    /** @var list<string> */
    protected $fillable = [
        'payroll_run_id',
        'year',
        'agency',
        'due_on',
        'downloaded_at',
        'submitted_at',
        'submitted_by_id',
        'receipt_reference',
        'amount_paid',
    ];

    protected function casts(): array
    {
        return [
            'due_on' => 'date',
            'downloaded_at' => 'datetime',
            'submitted_at' => 'datetime',
            'amount_paid' => 'float',
        ];
    }

    public function payrollRun(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class);
    }

    /** Where this filing stands right now: not_started, file_ready, submitted, overdue. */
    public function state(): string
    {
        if ($this->submitted_at !== null) {
            return 'submitted';
        }
        if ($this->due_on !== null && $this->due_on->isPast()) {
            return 'overdue';
        }

        return $this->downloaded_at !== null ? 'file_ready' : 'not_started';
    }
}
