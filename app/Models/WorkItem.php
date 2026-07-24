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

    /**
     * Fixed kanban label palette: slug => [display name, chip color]. Cards store
     * an array of these slugs in the `labels` JSON column. Mirror any change in
     * resources/js/work-board.js (LABELS) so client-side repaint stays in sync.
     */
    public const LABELS = [
        'urgent' => ['Urgent', '#e5484d'],
        'blocked' => ['Blocked', '#f76808'],
        'waiting' => ['Waiting', '#9a6700'],
        'review' => ['Review', '#3a6ea5'],
        'client' => ['Client', '#8a4bdb'],
        'internal' => ['Internal', '#5a6b7b'],
    ];

    protected function casts(): array
    {
        return ['due_at' => 'date', 'assigned_at' => 'datetime', 'labels' => 'array'];
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
     * Human due text for a card: the real due date if set, otherwise a legacy
     * free-text label. Empty string when neither exists. The structured date wins
     * so cards edited through the date picker show the real date even if an old
     * free-text label lingers.
     */
    public function dueText(): string
    {
        return $this->due_at?->format('d M Y') ?? ($this->due_label ?: '');
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
