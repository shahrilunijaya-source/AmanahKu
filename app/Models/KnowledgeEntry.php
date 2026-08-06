<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KnowledgeEntry extends Model
{
    use BelongsToTenant;

    /** The only reactions the UI offers. Anything else is rejected with a 422. */
    public const EMOJI = ['👍', '👏', '🔥', '💡', '🤔', '❤️'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['tags' => 'array'];
    }

    public function segment(): BelongsTo
    {
        return $this->belongsTo(KnowledgeSegment::class, 'seg_id');
    }

    public function subSegment(): BelongsTo
    {
        return $this->belongsTo(KnowledgeSegment::class, 'subseg_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function reads(): HasMany
    {
        return $this->hasMany(KnowledgeRead::class, 'entry_id');
    }

    public function stars(): HasMany
    {
        return $this->hasMany(KnowledgeStar::class, 'entry_id');
    }

    /** @return HasMany<KnowledgeComment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(KnowledgeComment::class, 'entry_id');
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(KnowledgeReaction::class, 'entry_id');
    }

    /** @return HasMany<KnowledgeAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(KnowledgeAttachment::class, 'entry_id')->orderBy('sort_order');
    }
}
