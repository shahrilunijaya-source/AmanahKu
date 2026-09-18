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
        'label',
        'run_by_id',
        'approved_by_id',
        'notes',
        'payment_date',
        'pull_options',
        'excluded_employee_ids',
    ];

    /** Sources a run can pull in; the new-run form shows one tick per key. */
    public const PULL_SOURCES = ['fixed', 'claims', 'overtime', 'unpaid'];

    protected function casts(): array
    {
        return [
            'totals' => 'array',
            'finalized_at' => 'datetime',
            'paid_at' => 'datetime',
            'payment_date' => 'date',
            'pull_options' => 'array',
            'excluded_employee_ids' => 'array',
        ];
    }

    /** EA s.19: wages are due no later than the seventh day after the wage period ends. */
    public function payByDate(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d', $this->period.'-01')->endOfMonth()->startOfDay()->addDays(7);
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

    public function runBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'run_by_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_id');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isFinalized(): bool
    {
        return $this->status === 'finalized';
    }

    /** Payslips can be edited while the run is not yet finalized. */
    public function isEditable(): bool
    {
        return $this->status !== 'finalized';
    }
}
