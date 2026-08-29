<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort' => 'integer',
        ];
    }

    /**
     * Timesheet categories this project falls under (e.g. Development, Maintenance).
     * This screen is the source of truth: a board card booked to the project is offered
     * exactly these, and inherits the answer outright when there is only one.
     *
     * @return BelongsToMany<TimesheetCategory, $this>
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(TimesheetCategory::class, 'project_timesheet_category');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(TimesheetEntry::class);
    }

    /** Board cards that name this project. Counted on the Projects register. */
    public function workItems(): HasMany
    {
        return $this->hasMany(WorkItem::class, 'project_id');
    }
}
