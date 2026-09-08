<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One "lessons learnt" entry per attendee per event. Mirrored into a Knowledge Bank
 * entry (segment "Events") by EventController::storeLesson(); knowledge_entry_id keys
 * that mirror so a second save updates the same Knowledge row instead of duplicating
 * it. Not a foreign key — the Knowledge module can be off for a tenant, and this row
 * must not disappear if that entry is ever removed by hand.
 */
class EventLesson extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['links' => 'array'];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(CompanyEvent::class, 'company_event_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(EventComment::class, 'lesson_id');
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(EventReaction::class, 'lesson_id');
    }
}
