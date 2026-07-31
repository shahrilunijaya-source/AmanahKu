<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FeedbackItem extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** Screenshots + documents the reporter attached, in upload order. */
    public function attachments(): HasMany
    {
        return $this->hasMany(FeedbackAttachment::class)->oldest('id');
    }
}
