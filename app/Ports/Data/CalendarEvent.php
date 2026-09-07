<?php

namespace App\Ports\Data;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * An event as the calendar port sees it. `subject` is the app row the event stands for
 * (a work item, a TOT session) so the outbox row can point back at it; `externalId` is
 * set when the event already exists in the calendar.
 */
final readonly class CalendarEvent
{
    public function __construct(
        public string $title,
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
        public ?string $description = null,
        public ?Model $subject = null,
        public ?string $externalId = null,
        public bool $allDay = false,
    ) {}

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'title' => $this->title,
            'starts_at' => $this->startsAt->toIso8601String(),
            'ends_at' => $this->endsAt->toIso8601String(),
            'description' => $this->description,
            'external_id' => $this->externalId,
            'all_day' => $this->allDay,
        ];
    }
}
