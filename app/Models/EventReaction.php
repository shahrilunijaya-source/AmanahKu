<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's reaction (CR-30 key set) on an event, or on one of its lessons when
 * `lesson_id` is set. Toggle semantics: EventController::react()/lessonReact() delete
 * any existing reaction from that person on that target before writing a new one.
 */
class EventReaction extends Model
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

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(EventLesson::class, 'lesson_id');
    }
}
