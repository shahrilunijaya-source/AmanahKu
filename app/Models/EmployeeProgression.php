<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One employment event (hired, confirmed, updated, resigned, rehired). Append-only: the
 * Timeline tab is built from these rows, so a row is never edited or deleted.
 *
 * @property array<string, mixed>|null $snapshot
 */
class EmployeeProgression extends Model
{
    use BelongsToTenant;

    public const TYPES = ['hired', 'confirmed', 'updated', 'resigned', 'withdrawn', 'rehired'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'effective_on' => 'date',
            'snapshot' => 'array',
            'changed_fields' => 'array',
        ];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<Employee, $this> */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'recorded_by_employee_id');
    }
}
