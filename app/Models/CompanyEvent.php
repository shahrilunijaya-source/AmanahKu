<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $event_date
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
 * @property list<int>|null $tagged_employee_ids
 */
class CompanyEvent extends Model
{
    use BelongsToTenant;

    /**
     * CR-18 pulls the smallest CR-11 lifecycle forward: an event is drafted, approved by
     * a director or PM, marked Held by its organiser (never by the date alone), or
     * cancelled. CR-11 builds the screens; the columns and the done rule live here.
     */
    public const STATUS_DRAFT = 'draft';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_HELD = 'held';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_APPROVED, self::STATUS_HELD, self::STATUS_CANCELLED];

    /** An RSVP response recorded after the event: the person was there. */
    public const RESPONSE_ATTENDED = 'attended';

    /** CR-11: signed up ahead of an external/registration-style event. */
    public const RESPONSE_REGISTERED = 'registered';

    /** Every RSVP response the attendees endpoint and the page may show. */
    public const RESPONSES = ['going', self::RESPONSE_REGISTERED, self::RESPONSE_ATTENDED, 'maybe', 'declined'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'event_date' => 'date', 'tagged_employee_ids' => 'array', 'approved_at' => 'datetime',
            'starts_at' => 'datetime', 'ends_at' => 'datetime',
        ];
    }

    public function rsvps(): HasMany
    {
        return $this->hasMany(EventRsvp::class);
    }

    public function photos(): HasMany
    {
        return $this->hasMany(EventPhoto::class)->orderBy('sort_order')->orderBy('id');
    }

    public function lessons(): HasMany
    {
        return $this->hasMany(EventLesson::class);
    }

    /** Top-level comments only; replies are read off each one's own replies() relation. */
    public function comments(): HasMany
    {
        return $this->hasMany(EventComment::class)->whereNull('parent_id')->oldest();
    }

    /** Reactions on the event itself, not on one of its lessons. */
    public function reactions(): HasMany
    {
        return $this->hasMany(EventReaction::class)->whereNull('lesson_id');
    }

    /**
     * Employees @mentioned in the description, who are expected to register. Kept as a
     * plain id list rather than a pivot: nothing tracks follow-through, so this is read
     * whole and checked in PHP — never with whereJsonContains, which sqlite and MySQL
     * disagree about.
     *
     * @return list<int>
     */
    public function taggedIds(): array
    {
        return array_map('intval', $this->tagged_employee_ids ?? []);
    }

    /** @return BelongsTo<Employee, $this> */
    public function poster(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by_employee_id');
    }

    /**
     * An event with a host is an outside-hosted one — a partner's workshop, a vendor
     * webinar forwarded into the company. That's the only marker; there is no separate
     * flag column. An external event gets a registration link instead of RSVP.
     */
    public function isExternal(): bool
    {
        return $this->host !== null;
    }

    /**
     * Post-event sharing (CR-11) unlocks once the event is over: `ends_at` when the
     * event carries one, otherwise the end of `event_date`. Compared against the app
     * clock (Carbon::now(), which the dev clock override and tests both drive via
     * Carbon::setTestNow), never a bare `now()` inline — see docs/build/contracts/dates.md.
     */
    public function isOver(): bool
    {
        $end = $this->ends_at ?? $this->event_date?->copy()->endOfDay();

        return $end !== null && Carbon::now()->greaterThanOrEqualTo($end);
    }

    /** The moment this event starts, for the calendar port and the card due date. */
    public function startsAtOrDate(): Carbon
    {
        return $this->starts_at ?? $this->event_date->copy()->startOfDay();
    }

    /** The moment this event ends, for the calendar port. Falls back to a one-hour slot. */
    public function endsAtOrDate(): Carbon
    {
        return $this->ends_at ?? $this->startsAtOrDate()->copy()->addHour();
    }
}
