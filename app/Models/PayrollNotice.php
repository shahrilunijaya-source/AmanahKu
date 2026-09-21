<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Spec F11: one statutory notice an employee's hire or leaving date opened. See
 * App\Services\Payroll\LifecycleNotices for when each type is created and what holds
 * a final pay run.
 *
 * @property Carbon|null $due_on
 * @property Carbon|null $filed_on
 * @property Carbon|null $cleared_on
 * @property-read Employee|null $employee
 */
class PayrollNotice extends Model
{
    use BelongsToTenant;

    public const array TYPES = ['cp22', 'cp22a', 'cp21', 'socso_form2', 'kwsp_registration'];

    /** @var list<string> */
    protected $fillable = [
        'employee_id',
        'type',
        'due_on',
        'filed_on',
        'reference',
        'filed_by_id',
        'note',
        'cleared_on',
    ];

    protected function casts(): array
    {
        return [
            'due_on' => 'date',
            'filed_on' => 'date',
            'cleared_on' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** Not filed yet. */
    public function isOpen(): bool
    {
        return $this->filed_on === null;
    }

    /** Open and the due date has passed. */
    public function isOverdue(): bool
    {
        return $this->isOpen() && $this->due_on !== null && $this->due_on->isPast();
    }
}
