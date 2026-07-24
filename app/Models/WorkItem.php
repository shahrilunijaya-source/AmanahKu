<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkItem extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['due_at' => 'date', 'assigned_at' => 'datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** The superior who assigned this task. Null for self-created cards. */
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assigned_by_id');
    }

    /**
     * Human due text for a card: the free-text label if set, otherwise the
     * structured assigned due date formatted. Empty string when neither exists.
     * Lets assigned tacs (which carry a real due_at, no label) still show a date.
     */
    public function dueText(): string
    {
        return $this->due_label ?: ($this->due_at?->format('d M Y') ?? '');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(WorkItemComment::class)->oldest();
    }

    /**
     * People included on this card beyond its owner. The same shared card appears
     * on every participant's board; they may view / move / comment but not edit.
     *
     * @return BelongsToMany<Employee, $this>
     */
    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'work_item_participant');
    }
}
