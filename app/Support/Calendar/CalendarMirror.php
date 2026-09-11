<?php

declare(strict_types=1);

namespace App\Support\Calendar;

use App\Models\WorkItem;
use App\Ports\Data\CalendarEvent;
use Carbon\CarbonImmutable;

/**
 * CR-01: how a card looks as a calendar event. One builder so the push job, the
 * snap-back after a moved entry and the reconnect re-mirror all send the same thing.
 * Title = card title; description = type, project tag, priority, assignees, link back.
 */
final class CalendarMirror
{
    /** A card the calendar should carry: dated, on the board, not closed, not a subtask. */
    public static function syncable(WorkItem $item): bool
    {
        return $item->due_at !== null
            && $item->parent_id === null
            && $item->archived_at === null
            && $item->cancelled_at === null
            && $item->status !== 'done';
    }

    public static function event(WorkItem $item): CalendarEvent
    {
        $start = CarbonImmutable::instance($item->due_at)->startOfDay();
        $companyEvent = $item->type === 'event' ? $item->companyEvent : null;
        if ($companyEvent) {
            $start = CarbonImmutable::instance($companyEvent->startsAtOrDate());
            $end = CarbonImmutable::instance($companyEvent->endsAtOrDate());
        } else {
            $end = $start->addDay();
        }

        return new CalendarEvent(
            title: $item->title,
            startsAt: $start,
            endsAt: $end,
            description: self::description($item),
            subject: $item,
            externalId: $item->google_event_id,
            allDay: $companyEvent === null,
            version: $item->calendar_version,
        );
    }

    public static function description(WorkItem $item): string
    {
        $project = $item->projectRef;
        $assignees = collect([$item->employee?->display_name])
            ->merge($item->participants->map(fn ($p) => $p->display_name))
            ->filter()->unique()->implode(', ');

        return collect([
            'Type: '.ucfirst($item->type),
            $project ? 'Project: '.($project->project_code ?: $project->code) : null,
            'Priority: '.ucfirst((string) $item->priority),
            $assignees !== '' ? 'Assignees: '.$assignees : null,
            route('work.show', $item),
        ])->filter()->implode("\n");
    }
}
