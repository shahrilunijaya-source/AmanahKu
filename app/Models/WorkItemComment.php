<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkItemComment extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['pushed_to_track_at' => 'datetime', 'withdrawn_at' => 'datetime', 'track_versions' => 'array'];
    }

    public function isPushedToTrack(): bool
    {
        return $this->pushed_to_track_at !== null;
    }

    /** @return BelongsTo<WorkItem, $this> */
    public function workItem(): BelongsTo
    {
        return $this->belongsTo(WorkItem::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return HasMany<WorkItemCommentAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(WorkItemCommentAttachment::class);
    }
}
