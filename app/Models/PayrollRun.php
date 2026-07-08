<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
    ];

    protected function casts(): array
    {
        return [
            'totals' => 'array',
            'finalized_at' => 'datetime',
        ];
    }

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
