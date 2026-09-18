<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property array<string, bool>|null $pull_options
 * @property Carbon|null $payment_date
 * @property Carbon|null $published_at
 */
class PayrollRun extends Model
{
    use BelongsToTenant;

    /**
     * Operator inputs only. status, finalized_at and the cached totals are lifecycle/
     * computed columns the controller writes with forceFill(), so they are deliberately
     * NOT mass-assignable. tenant_id is set by BelongsToTenant.
     *
     * @var list<string>
     */
    protected $fillable = [
        'period',
        'kind',
        'employee_id',
        'label',
        'run_by_id',
        'approved_by_id',
        'notes',
        'payment_date',
        'pull_options',
        'excluded_employee_ids',
    ];

    /**
     * Spec F10: monthly is the ordinary company-wide run (one per tenant and period);
     * bonus pays flagged Individual Transactions as additional remuneration alongside it;
     * final is one leaver's last pay. Only a final run carries an employee_id.
     */
    public const KINDS = ['monthly', 'bonus', 'final'];

    /** Sources a run can pull in; the new-run form shows one tick per key. */
    public const PULL_SOURCES = ['fixed', 'claims', 'overtime', 'unpaid'];

    protected function casts(): array
    {
        return [
            'totals' => 'array',
            'finalized_at' => 'datetime',
            'published_at' => 'datetime',
            'paid_at' => 'datetime',
            'payment_date' => 'date',
            'pull_options' => 'array',
            'excluded_employee_ids' => 'array',
        ];
    }

    /**
     * EA s.19: wages are due no later than the seventh day after the wage period ends.
     * A final pay run's wage period ends when the employee stops working (EA s.20), so
     * its seven days are counted from the last working day instead of the month end.
     */
    public function payByDate(): CarbonImmutable
    {
        $end = CarbonImmutable::createFromFormat('Y-m-d', $this->period.'-01')->endOfMonth()->startOfDay();
        $lastDay = $this->kind === 'final' ? $this->employee?->last_working_day : null;
        if ($lastDay !== null && $lastDay->lt($end)) {
            $end = CarbonImmutable::instance($lastDay)->startOfDay();
        }

        return $end->addDays(7);
    }

    /** Whether this run pulls the given source. A run with no stored choice pulls everything. */
    public function pulls(string $source): bool
    {
        return (bool) ($this->pull_options[$source] ?? true);
    }

    /** @return HasMany<Payslip, $this> */
    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    /** Only a final pay run names one employee; every other kind covers the company. */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function runBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'run_by_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    public function isBonus(): bool
    {
        return $this->kind === 'bonus';
    }

    public function isFinal(): bool
    {
        return $this->kind === 'final';
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isFinalized(): bool
    {
        return $this->status === 'finalized';
    }

    /**
     * Spec F13: staff see a payslip only once the run is published, which is a separate
     * step after finalize (finalize may also publish immediately, see publishRun()).
     */
    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }

    /** Payslips can be edited while the run is not yet finalized. */
    public function isEditable(): bool
    {
        return $this->status !== 'finalized';
    }
}
