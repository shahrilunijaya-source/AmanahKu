<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file on a card comment (CR-08). Private disk, reached only through
 * WorkItemController::commentAttachment. `confidential` pins it to the card;
 * `pushed_to_track` says Track was given a link to it.
 */
class WorkItemCommentAttachment extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected $casts = ['size' => 'integer', 'confidential' => 'boolean', 'pushed_to_track' => 'boolean'];

    public function comment(): BelongsTo
    {
        return $this->belongsTo(WorkItemComment::class, 'work_item_comment_id');
    }

    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime, 'image/');
    }
}
