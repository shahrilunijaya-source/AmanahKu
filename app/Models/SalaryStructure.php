<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalaryStructure extends Model
{
    use BelongsToTenant;

    /**
     * Operator-supplied salary inputs only. tenant_id is set by BelongsToTenant; there
     * are no controller-computed columns on this table, so the full input set is fillable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'employee_id',
        'basic_salary',
        'allowances',
        'effective_from',
        'bank_name',
        'bank_account_no',
        'epf_no',
        'socso_no',
        'nric',
    ];

    protected function casts(): array
    {
        return [
            'basic_salary' => 'float',
            'allowances' => 'array',
            'effective_from' => 'date',
            'nric' => 'encrypted',   // PII at rest (I-018); statutory reports decrypt on read
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** Sum of all fixed allowance lines. */
    public function allowancesTotal(): float
    {
        return collect($this->allowances ?? [])->sum(fn ($a) => (float) ($a['amount'] ?? 0));
    }
}
