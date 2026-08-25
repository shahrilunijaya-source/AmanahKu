<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $event_date
 */
class ExternalTotEvent extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['event_date' => 'date', 'tagged_employee_ids' => 'array'];
    }

    /**
     * Employees @mentioned in the description, who are expected to register. Kept as a
     * plain id list rather than a pivot: nothing tracks follow-through, so this is read
     * whole and checked in PHP — never with whereJsonContains, which sqlite and MySQL
     * disagree about.
     *
     * @return list<int>
     */
    public function taggedIds(): array
    {
        return array_map('intval', $this->tagged_employee_ids ?? []);
    }

    /** @return BelongsTo<Employee, $this> */
    public function poster(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'posted_by');
    }
}
