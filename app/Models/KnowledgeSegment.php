<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class KnowledgeSegment extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    /** Sub-segments of a top-level segment (one level deep). */
    public function children(): HasMany
    {
        return $this->hasMany(KnowledgeSegment::class, 'parent_id')->orderBy('sort_order')->orderBy('id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(KnowledgeSegment::class, 'parent_id');
    }

    /** Entries filed directly under this segment (as the primary segment). */
    public function entries(): HasMany
    {
        return $this->hasMany(KnowledgeEntry::class, 'seg_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }
}
