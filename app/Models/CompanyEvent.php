<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $event_date
 * @property list<int>|null $tagged_employee_ids
 */
class CompanyEvent extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['event_date' => 'date', 'tagged_employee_ids' => 'array'];
    }

    public function rsvps(): HasMany
    {
        return $this->hasMany(EventRsvp::class);
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
        return $this->belongsTo(Employee::class, 'created_by_employee_id');
    }

    /**
     * An event with a host is an outside-hosted one — a partner's workshop, a vendor
     * webinar forwarded into the company. That's the only marker; there is no separate
     * flag column. An external event gets a registration link instead of RSVP.
     */
    public function isExternal(): bool
    {
        return $this->host !== null;
    }
}
