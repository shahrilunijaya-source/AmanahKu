<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One post on an event's discussion thread, optionally a reply or scoped to one lesson. */
class EventComment extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    public function event(): BelongsTo
    {
        return $this->belongsTo(CompanyEvent::class, 'company_event_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(EventComment::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(EventComment::class, 'parent_id')->oldest();
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(EventLesson::class, 'lesson_id');
    }
}
