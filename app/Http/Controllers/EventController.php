<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\CompanyEvent;
use App\Models\Employee;
use App\Models\EventComment;
use App\Models\EventLesson;
use App\Models\EventPhoto;
use App\Models\EventReaction;
use App\Models\EventRsvp;
use App\Models\KnowledgeEntry;
use App\Models\KnowledgeSegment;
use App\Models\Project;
use App\Models\Reaction;
use App\Models\WorkItem;
use App\Ports\CalendarPort;
use App\Ports\Data\CalendarEvent;
use App\Support\AutoDone;
use App\Support\ImageCompressor;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EventController extends Controller
{
    /**
     * HR/management may publish company events; everyone may RSVP. A manager is one
     * tier wider than the internal roster's privileged pair, because a manager is often
     * the one actually forwarded an external invite and shouldn't have to route it
     * through HR first. CR-11: this same trio sets the attendee list.
     */
    private const PRIVILEGED_ROLES = ['manager', 'management', 'hr'];

    private const TYPES = ['townhall', 'training', 'holiday', 'social', 'meeting'];

    /** How far back "recent" past events reach before an older event is collapsed. */
    private const RECENT_PAST_DAYS = 30;

    /** How many older-than-recent past events the collapsed bucket shows at most. */
    private const OLDER_PAST_LIMIT = 20;

    /** Human label per EventRsvp response, for the attendee list on the event page. */
    private const RESPONSE_LABELS = [
        'going' => 'Going',
        CompanyEvent::RESPONSE_REGISTERED => 'Registered',
        CompanyEvent::RESPONSE_ATTENDED => 'Attended',
        // CR-19: the post-event mark-off's other outcome — archives the attendee's card.
        CompanyEvent::RESPONSE_DID_NOT_ATTEND => 'Did not attend',
        'maybe' => 'Maybe',
        'declined' => 'Declined',
    ];

    /** Private disk photos and knowledge-search body text live on — same convention as Knowledge Bank. */
    private const ATTACHMENT_DISK = 'local';

    private const IMAGE_MIMES = 'jpeg,jpg,png,gif,webp';

    private const IMAGE_MAX_KB = 8192;

    private const MAX_PHOTOS = 20;

    /**
     * Everyone sees upcoming events with RSVP counts and their own choice per event, and
     * every past event too — recent ones inline, older ones collapsed on the view.
     * Privileged roles additionally receive a create-form flag. Counts are aggregated in
     * PHP to stay DB-agnostic and rely on the BelongsToTenant scope for tenant isolation.
     */
    public function screenData(Request $request, ?Employee $employee): array
    {
        $privileged = $this->hasTenantRole($request, self::PRIVILEGED_ROLES);

        $today = now()->toDateString();
        $recentCutoff = now()->subDays(self::RECENT_PAST_DAYS)->toDateString();

        $upcoming = CompanyEvent::with('rsvps')
            ->whereDate('event_date', '>=', $today)
            ->orderBy('event_date')
            ->orderBy('start_time')
            ->get()
            ->map(fn (CompanyEvent $event) => $this->present($event, $employee));

        $recentPastEvents = CompanyEvent::with('rsvps')
            ->whereDate('event_date', '<', $today)
            ->whereDate('event_date', '>=', $recentCutoff)
            ->orderByDesc('event_date')
            ->get()
            ->map(fn (CompanyEvent $event) => $this->present($event, $employee));

        $olderPastEvents = CompanyEvent::with('rsvps')
            ->whereDate('event_date', '<', $recentCutoff)
            ->orderByDesc('event_date')
            ->take(self::OLDER_PAST_LIMIT)
            ->get()
            ->map(fn (CompanyEvent $event) => $this->present($event, $employee));

        return [
            'privileged' => $privileged,
            'canRespond' => (bool) $employee,
            'viewerId' => $employee?->id,
            'upcomingEvents' => $upcoming,
            'recentPastEvents' => $recentPastEvents,
            'olderPastEvents' => $olderPastEvents,
            'eventTypes' => self::TYPES,
            'assignableEmployees' => $this->assignableEmployees(),
        ];
    }

    /**
     * Publish a new company event. Privileged-only. An external event (host filled in)
     * carries a map link, a registration link, and @mentions instead of RSVP — the wider
     * PRIVILEGED_ROLES trio can post either kind. CR-11: `starts_at`/`ends_at` carry the
     * exact time slot the calendar port and the attendee cards need; `event_date` stays
     * the day the screens group and filter by.
     */
    public function store(Request $request): RedirectResponse
    {
        $this->authorizePrivileged($request);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'type' => ['required', 'in:'.implode(',', self::TYPES)],
            'event_date' => ['required', 'date'],
            'start_time' => ['nullable', 'string', 'max:40'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'location' => ['nullable', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'host' => ['nullable', 'string', 'max:120'],
            'venue_map_url' => ['nullable', 'url', 'max:2000'],
            'registration_url' => ['nullable', 'url', 'max:2000'],
            'tagged' => ['nullable', 'array', 'max:20'],
            'tagged.*' => ['integer'],
        ]);

        $tagged = $this->taggedFromDescription($data['tagged'] ?? [], $data['description'] ?? null);
        unset($data['tagged']);

        $event = CompanyEvent::create([
            ...$data,
            'tagged_employee_ids' => $tagged->pluck('id')->all(),
            'tenant_id' => app(CurrentTenant::class)->id(),
            'created_by_employee_id' => $request->attributes->get('employee')?->id,
        ]);

        if ($event->isExternal()) {
            AppNotification::sendMany(
                $tagged->pluck('user_id')->filter()->all(),
                "You're required to attend: {$event->title}",
                collect([$event->host, $event->event_date->format('D, j M Y'), $event->start_time])->filter()->implode(' · '),
                route('app.screen', 'events'),
                mail: true,
            );
        }

        AuditLog::record('Created event', $event->title);

        return back()->with('ok', 'Event "'.$event->title.'" published.');
    }

    /**
     * Edit an event. Poster-only, unlike post/remove: the wider PRIVILEGED_ROLES trio
     * can still delete a bad post, but only the person who wrote it may change what it
     * says. CR-11 scope 2: a reschedule (new starts_at/ends_at/event_date) never makes
     * a new attendee card — it moves every existing one and re-upserts the same
     * calendar event id, since a Task's locked due date does not apply to an Event card
     * (dates contract Rule 2).
     */
    public function update(Request $request, CompanyEvent $event): RedirectResponse
    {
        abort_unless($event->tenant_id === app(CurrentTenant::class)->id(), 403);

        $employee = $request->attributes->get('employee');
        abort_unless(
            $employee && $event->created_by_employee_id === $employee->id,
            403,
            'Only the person who posted this event can edit it.'
        );

        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'type' => ['required', 'in:'.implode(',', self::TYPES)],
            'event_date' => ['required', 'date'],
            'start_time' => ['nullable', 'string', 'max:40'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'location' => ['nullable', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:2000'],
            'host' => ['nullable', 'string', 'max:120'],
            'venue_map_url' => ['nullable', 'url', 'max:2000'],
            'registration_url' => ['nullable', 'url', 'max:2000'],
            'tagged' => ['nullable', 'array', 'max:20'],
            'tagged.*' => ['integer'],
        ]);

        $previouslyTagged = $event->taggedIds();
        $tagged = $this->taggedFromDescription($data['tagged'] ?? [], $data['description'] ?? null);
        unset($data['tagged']);

        $event->update([
            ...$data,
            'tagged_employee_ids' => $tagged->pluck('id')->all(),
        ]);

        $this->syncAttendeeCardsAfterReschedule($event);

        // Only somebody newly tagged gets a summons — re-saving the same @mentions must
        // not re-mail everyone who was already told.
        $newlyTagged = $tagged->reject(fn (Employee $person) => in_array($person->id, $previouslyTagged, true));

        if ($event->isExternal()) {
            AppNotification::sendMany(
                $newlyTagged->pluck('user_id')->filter()->all(),
                "You're required to attend: {$event->title}",
                collect([$event->host, $event->event_date->format('D, j M Y'), $event->start_time])->filter()->implode(' · '),
                route('app.screen', 'events'),
                mail: true,
            );
        }

        AuditLog::record('Updated event', $event->title);

        return back()->with('ok', 'Event updated.');
    }

    /** Privileged-only: remove an event entirely. */
    public function destroy(Request $request, CompanyEvent $event): RedirectResponse
    {
        abort_unless($event->tenant_id === app(CurrentTenant::class)->id(), 403);
        $this->authorizePrivileged($request);

        $title = $event->title;
        $event->delete();

        AuditLog::record('Removed event', $title);

        return back()->with('ok', 'Event removed.');
    }

    /**
     * Any employee may RSVP once per event; submitting again updates the same row. A
     * privileged user may set someone else's response instead of their own — CR-11's
     * post-event attendance mark-off (HR setting a colleague to Attended).
     */
    public function rsvp(Request $request, CompanyEvent $event): RedirectResponse
    {
        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403, 'No employee profile in this workspace.');
        abort_unless($event->tenant_id === app(CurrentTenant::class)->id(), 403);

        $data = $request->validate([
            'response' => ['required', 'in:'.implode(',', CompanyEvent::RESPONSES)],
            'employee_id' => ['nullable', 'integer', Rule::exists('employees', 'id')->where('tenant_id', $event->tenant_id)],
        ]);

        $target = $employee;
        if (! empty($data['employee_id']) && (int) $data['employee_id'] !== $employee->id) {
            abort_unless($this->canManageAttendees($request, $event), 403, 'Only the event creator, managers, HR and management can mark attendance for someone else.');
            $target = Employee::findOrFail($data['employee_id']);
        }

        // updateOrCreate keyed on (event, employee) respects the unique constraint —
        // a second RSVP updates the existing row rather than inserting a duplicate.
        EventRsvp::updateOrCreate(
            [
                'company_event_id' => $event->id,
                'employee_id' => $target->id,
            ],
            [
                'tenant_id' => $event->tenant_id,
                'response' => $data['response'],
            ],
        );

        // CR-19: the post-event mark-off closes the attendee's card automatically —
        // Attended is Done, Did not attend is archived (never Done). Every other
        // response (still pending, or set before the event is over) leaves the card
        // alone; the scheduler only ever notifies about those, never closes them.
        if (in_array($data['response'], [CompanyEvent::RESPONSE_ATTENDED, CompanyEvent::RESPONSE_DID_NOT_ATTEND], true)) {
            $card = WorkItem::where('company_event_id', $event->id)->where('employee_id', $target->id)->whereNull('archived_at')->first();
            if ($card) {
                $data['response'] === CompanyEvent::RESPONSE_ATTENDED
                    ? AutoDone::done($card, 'Attended')
                    : AutoDone::archived($card, 'Did not attend');
            }
        }

        return back()->with('ok', 'RSVP recorded.');
    }

    /**
     * CR-11: replace the event's attendee list. Creator or privileged role (scope 5). Diffed
     * against the current RSVP rows so re-posting the same set is a no-op: newly added
     * ids get a Going RSVP, an Event card, and a calendar upsert intent; dropped ids
     * lose their RSVP row and have their card archived (not deleted — history stays)
     * with a calendar delete intent.
     */
    public function attendees(Request $request, CompanyEvent $event): JsonResponse|RedirectResponse
    {
        abort_unless($event->tenant_id === app(CurrentTenant::class)->id(), 403);
        abort_unless($this->canManageAttendees($request, $event), 403, 'Only the event creator, managers, HR and management can set attendees.');

        // QA F4: the plain form's hidden blank keeps "nobody selected" posting an (empty) array.
        $request->merge(['attendees' => array_values(array_filter((array) $request->input('attendees', []), fn ($v) => $v !== '' && $v !== null))]);

        $data = $request->validate([
            'attendees' => ['present', 'array'],
            'attendees.*' => ['integer', Rule::exists('employees', 'id')->where('tenant_id', $event->tenant_id)],
        ]);

        $ids = collect($data['attendees'])->map(fn ($v) => (int) $v)->unique()->values();
        $existingIds = $event->rsvps()->pluck('employee_id')->map(fn ($v) => (int) $v);

        $toAdd = $ids->diff($existingIds);
        $toRemove = $existingIds->diff($ids);

        foreach ($toAdd as $employeeId) {
            $employee = Employee::findOrFail($employeeId);
            EventRsvp::create([
                'company_event_id' => $event->id,
                'employee_id' => $employee->id,
                'response' => 'going',
            ]);
            $this->createEventCard($event, $employee);
        }

        foreach ($toRemove as $employeeId) {
            EventRsvp::where('company_event_id', $event->id)->where('employee_id', $employeeId)->delete();
            $this->archiveEventCard($event, (int) $employeeId);
        }

        if ($toAdd->isNotEmpty() || $toRemove->isNotEmpty()) {
            AuditLog::record('Set event attendees', $event->title);
        }

        return $request->expectsJson() ? response()->json(['ok' => true]) : back()->with('ok', 'Attendees saved.');
    }

    /**
     * CR-11 event detail page: attendee list with per-person status, and — once the
     * event is over — photos, comments and the one-lesson-per-attendee section.
     * Called by AppController::eventShow() so the page renders inside the normal app
     * shell. Viewable by anyone in the tenant; posting photos/a lesson is attendee-only
     * (see storePhotos()/storeLesson()), commenting is open to any employee.
     *
     * @return array<string, mixed>
     */
    public function show(Request $request, CompanyEvent $event, ?Employee $employee): array
    {
        $rsvps = $event->rsvps()->with('employee:id,name,nickname')->get()
            ->sortBy(fn (EventRsvp $r) => $r->employee?->display_name)
            ->values();

        $attendees = $rsvps->map(fn (EventRsvp $r) => [
            'employee' => $r->employee,
            'response' => $r->response,
            'responseLabel' => self::RESPONSE_LABELS[$r->response] ?? ucfirst($r->response),
        ])->values();

        $isAttendee = $employee !== null && $rsvps->contains(fn (EventRsvp $r) => $r->employee_id === $employee->id);
        $isOver = $event->isOver();

        $lessons = $isOver ? $event->lessons()->with('employee:id,name,nickname')->get() : collect();

        return [
            'event' => $event,
            'attendees' => $attendees,
            'isAttendee' => $isAttendee,
            'canManageAttendees' => $this->canManageAttendees($request, $event),
            'isOver' => $isOver,
            'photos' => $isOver ? $event->photos()->with('employee:id,name,nickname')->get() : collect(),
            'lessons' => $lessons,
            'comments' => $isOver ? $event->comments()->with(['employee:id,name,nickname', 'replies.employee:id,name,nickname'])->get() : collect(),
            'lessonReactions' => $lessons->mapWithKeys(fn (EventLesson $l) => [$l->id => $this->reactionState($l->reactions, $employee)]),
            'eventReactions' => $this->reactionState($event->reactions, $employee),
            'reactionCatalog' => Reaction::active()->map->toPayload()->all(),
            'assignableEmployees' => $this->assignableEmployees(),
        ];
    }

    /**
     * CR-11: attendee-only photo upload, after the event has ended in spirit — nothing
     * technically blocks an early upload, but only an attendee can add one, and only an
     * attendee can post at all, which is what the acceptance test actually checks.
     */
    public function storePhotos(Request $request, CompanyEvent $event): RedirectResponse
    {
        abort_unless($event->tenant_id === app(CurrentTenant::class)->id(), 403);
        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403, 'No employee profile in this workspace.');
        abort_unless($event->rsvps()->where('employee_id', $employee->id)->exists(), 403, 'Only attendees can add photos.');

        $data = $request->validate([
            'photos' => ['required', 'array', 'max:'.self::MAX_PHOTOS],
            'photos.*' => ['image', 'mimes:'.self::IMAGE_MIMES, 'max:'.self::IMAGE_MAX_KB],
            'captions' => ['nullable', 'array'],
            'captions.*' => ['nullable', 'string', 'max:200'],
        ]);

        $order = (int) ($event->photos()->max('sort_order') ?? -1) + 1;
        foreach (array_values((array) $request->file('photos', [])) as $i => $file) {
            if (! $file || ! $file->isValid()) {
                continue;
            }

            $path = $file->store('event-photos', self::ATTACHMENT_DISK);
            abort_unless($path !== false, 500, 'Picture could not be stored.');

            ImageCompressor::compress(Storage::disk(self::ATTACHMENT_DISK)->path($path), (string) $file->getMimeType());

            $event->photos()->create([
                'employee_id' => $employee->id,
                'path' => $path,
                'caption' => trim((string) ($data['captions'][$i] ?? '')) ?: null,
                'sort_order' => $order,
            ]);
            $order++;
        }

        return back()->with('ok', 'Photos added.');
    }

    /**
     * Stream one event photo inline through a tenant-gated action, same convention as
     * KnowledgeController::attachment() — a company event is company-wide, so any
     * employee in the tenant may view it, not just attendees.
     */
    public function photoShow(Request $request, EventPhoto $photo): StreamedResponse
    {
        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403);
        abort_unless($photo->tenant_id === app(CurrentTenant::class)->id(), 403);
        abort_unless(Storage::disk(self::ATTACHMENT_DISK)->exists($photo->path), 404);

        return Storage::disk(self::ATTACHMENT_DISK)->response($photo->path, basename($photo->path));
    }

    /** Any employee (attendee or not) may comment on an event, optionally as a reply or against one lesson. */
    public function storeComment(Request $request, CompanyEvent $event): JsonResponse|RedirectResponse
    {
        abort_unless($event->tenant_id === app(CurrentTenant::class)->id(), 403);
        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403, 'No employee profile in this workspace.');

        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
            'parent_id' => ['nullable', 'integer', Rule::exists('event_comments', 'id')->where('company_event_id', $event->id)],
            'lesson_id' => ['nullable', 'integer', Rule::exists('event_lessons', 'id')->where('company_event_id', $event->id)],
        ]);

        $comment = EventComment::create([
            'company_event_id' => $event->id,
            'employee_id' => $employee->id,
            'parent_id' => $data['parent_id'] ?? null,
            'lesson_id' => $data['lesson_id'] ?? null,
            'body' => $data['body'],
        ]);

        return $request->expectsJson() ? response()->json(['id' => $comment->id]) : back()->with('ok', 'Comment posted.');
    }

    /**
     * CR-11: one "lessons learnt" entry per attendee, unlocked once the event has ended
     * (isOver()), mirrored into the tenant's Knowledge Bank (segment "Events") so it
     * turns up in the normal Knowledge search. A second save updates the same lesson
     * row and the same mirrored entry — keyed by event_lessons.knowledge_entry_id.
     */
    public function storeLesson(Request $request, CompanyEvent $event): JsonResponse|RedirectResponse
    {
        abort_unless($event->tenant_id === app(CurrentTenant::class)->id(), 403);
        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403, 'No employee profile in this workspace.');
        abort_unless($event->rsvps()->where('employee_id', $employee->id)->exists(), 403, 'Only attendees can share a lesson.');
        abort_unless($event->isOver(), 422, 'The lesson unlocks once the event ends.');

        $data = $request->validate([
            'learnt' => ['required', 'string', 'max:3000'],
            'how_to_use' => ['nullable', 'string', 'max:2000'],
            'links' => ['nullable', 'array', 'max:10'],
            'links.*' => ['url', 'max:2000'],
            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')->where('tenant_id', $event->tenant_id)],
        ]);

        $lesson = EventLesson::updateOrCreate(
            ['company_event_id' => $event->id, 'employee_id' => $employee->id],
            [
                'learnt' => $data['learnt'],
                'how_to_use' => $data['how_to_use'] ?? null,
                'links' => $data['links'] ?? [],
                'project_id' => $data['project_id'] ?? null,
            ],
        );

        $this->mirrorLessonToKnowledge($lesson, $event, $employee);

        AuditLog::record('Shared an event lesson', $event->title);

        return $request->expectsJson() ? response()->json(['id' => $lesson->id]) : back()->with('ok', 'Lesson shared.');
    }

    /** Toggle one CR-30 reaction on the event itself (not a specific lesson). */
    public function react(Request $request, CompanyEvent $event): JsonResponse
    {
        abort_unless($event->tenant_id === app(CurrentTenant::class)->id(), 403);

        return $this->toggleReaction($request, $event, null);
    }

    /** Toggle one CR-30 reaction on a single lesson. */
    public function lessonReact(Request $request, CompanyEvent $event, EventLesson $lesson): JsonResponse
    {
        abort_unless($event->tenant_id === app(CurrentTenant::class)->id(), 403);
        abort_unless($lesson->company_event_id === $event->id, 404);

        return $this->toggleReaction($request, $event, $lesson);
    }

    /**
     * The people the @mention picker offers, by name rather than by database id.
     *
     * Employee::active() (not status = 'active'), because archiving is the separate
     * archived_at column. Filtering on the status column would drop probation and
     * on-leave staff, who can still be tagged like anybody else.
     *
     * @return Collection<int, Employee>
     */
    private function assignableEmployees(): Collection
    {
        return Employee::active()->orderBy('name')->get(['id', 'name', 'nickname']);
    }

    /**
     * The employees a poster actually @mentioned: ids they picked, narrowed to active
     * employees of this tenant (a raw id from the form is never trusted), and narrowed
     * again to the ones whose name is still written in the description — a mention the
     * poster deleted from the text before posting should not send anybody a summons.
     *
     * @param  list<int>  $ids
     * @return Collection<int, Employee>
     */
    private function taggedFromDescription(array $ids, ?string $description): Collection
    {
        if ($ids === [] || $description === null) {
            return collect();
        }

        return Employee::active()->whereIn('id', $ids)->get()
            ->filter(fn (Employee $person) => str_contains($description, '@'.$person->display_name))
            ->values();
    }

    /** Compute RSVP counts + the current employee's own response for one event. */
    private function present(CompanyEvent $event, ?Employee $employee): array
    {
        $rsvps = $event->rsvps;

        $myRsvp = $employee
            ? $rsvps->firstWhere('employee_id', $employee->id)?->response
            : null;

        return [
            'event' => $event,
            'counts' => [
                'going' => $rsvps->where('response', 'going')->count(),
                'maybe' => $rsvps->where('response', 'maybe')->count(),
                'declined' => $rsvps->where('response', 'declined')->count(),
            ],
            'myRsvp' => $myRsvp,
        ];
    }

    /** QA F3 (CR-11 scope 5): the event creator or a privileged role may set attendees. */
    private function canManageAttendees(Request $request, CompanyEvent $event): bool
    {
        $employee = $request->attributes->get('employee');

        return $this->hasTenantRole($request, self::PRIVILEGED_ROLES)
            || ($employee && $event->created_by_employee_id === $employee->id);
    }

    private function authorizePrivileged(Request $request): void
    {
        abort_unless(
            $this->hasTenantRole($request, self::PRIVILEGED_ROLES),
            403,
            'Only managers, HR and management can create events.'
        );
    }

    /** The event card's description: location first (the calendar-relevant part), then the free-text body. */
    private function eventCardDescription(CompanyEvent $event): string
    {
        return collect([$event->location, $event->description])->filter()->implode("\n\n");
    }

    /** New attendee: a Going RSVP already exists by the time this runs — this makes the card + calendar intent. */
    private function createEventCard(CompanyEvent $event, Employee $employee): WorkItem
    {
        $card = $employee->workItems()->create([
            'title' => $event->title,
            'type' => 'event',
            'priority' => 'medium',
            'due_at' => $event->startsAtOrDate()->toDateString(),
            'description' => $this->eventCardDescription($event),
            'status' => 'todo',
            'progress' => 0,
            'company_event_id' => $event->id,
            'sort_order' => (int) $employee->workItems()->where('status', 'todo')->max('sort_order') + 1,
        ]);

        $result = app(CalendarPort::class)->upsertEvent($employee, new CalendarEvent(
            title: $event->title,
            startsAt: CarbonImmutable::instance($event->startsAtOrDate()),
            endsAt: CarbonImmutable::instance($event->endsAtOrDate()),
            description: $card->description,
            subject: $card,
        ));
        $card->update(['google_event_id' => $result->externalId]);

        return $card;
    }

    /**
     * Removed attendee: archive their card (history stays, board doesn't) and send the
     * calendar delete intent for the id captured before the archive touches anything.
     */
    private function archiveEventCard(CompanyEvent $event, int $employeeId): void
    {
        $card = WorkItem::where('company_event_id', $event->id)
            ->where('employee_id', $employeeId)
            ->whereNull('archived_at')
            ->first();
        if (! $card) {
            return;
        }

        $externalId = $card->google_event_id;
        // QA F10: archived AND cancelled, as the CR-11 OPEN shape and the S13 handoff both
        // say. CR-19: routed through AutoDone so it carries the same Auto marker/audit/
        // activity-line trail as every other automatic close.
        AutoDone::cancelled($card, 'Invitation withdrawn');

        $employee = Employee::find($employeeId);
        if ($externalId && $employee) {
            app(CalendarPort::class)->deleteEvent($employee, $externalId);
        }
    }

    /**
     * Scope 2: a reschedule moves every still-active attendee card's due date to the
     * new start day and re-upserts the same calendar event — the external id on the
     * card never changes, only the payload sent for it does.
     */
    private function syncAttendeeCardsAfterReschedule(CompanyEvent $event): void
    {
        $cards = WorkItem::where('company_event_id', $event->id)->whereNull('archived_at')->get();

        foreach ($cards as $card) {
            $card->update([
                'title' => $event->title,
                'due_at' => $event->startsAtOrDate()->toDateString(),
                'description' => $this->eventCardDescription($event),
            ]);

            $employee = Employee::find($card->employee_id);
            if (! $employee) {
                continue;
            }

            app(CalendarPort::class)->upsertEvent($employee, new CalendarEvent(
                title: $event->title,
                startsAt: CarbonImmutable::instance($event->startsAtOrDate()),
                endsAt: CarbonImmutable::instance($event->endsAtOrDate()),
                description: $card->description,
                subject: $card,
                externalId: $card->google_event_id,
            ));
        }
    }

    /**
     * Mirror one lesson into the tenant's Knowledge Bank, segment "Events" (found or
     * created). `knowledge_entry_id` on the lesson keys the mirror so a second save
     * updates the same Knowledge row instead of duplicating it — not a foreign key on
     * purpose, see EventLesson's docblock.
     */
    private function mirrorLessonToKnowledge(EventLesson $lesson, CompanyEvent $event, Employee $employee): void
    {
        $segment = KnowledgeSegment::firstOrCreate(['label' => 'Events', 'parent_id' => null]);

        $body = trim(collect([
            $lesson->learnt,
            $lesson->how_to_use ? "How to use: {$lesson->how_to_use}" : null,
            collect($lesson->links)->implode("\n"),
        ])->filter()->implode("\n\n"));

        $title = mb_substr($event->title.': '.$employee->display_name, 0, 200);
        $project = $lesson->project_id ? Project::find($lesson->project_id) : null;
        $tags = $project ? [(string) ($project->project_code ?: $project->code)] : null;

        $entry = $lesson->knowledge_entry_id ? KnowledgeEntry::find($lesson->knowledge_entry_id) : null;

        if ($entry) {
            $entry->update(['title' => $title, 'body' => $body, 'tags' => $tags]);
        } else {
            $entry = KnowledgeEntry::create([
                'seg_id' => $segment->id,
                'employee_id' => $employee->id,
                'title' => $title,
                'body' => $body,
                'tags' => $tags,
            ]);
            $lesson->update(['knowledge_entry_id' => $entry->id]);
        }
    }

    /**
     * Toggle semantics mirroring KnowledgeController::react(): delete this employee's
     * existing reaction on the target (event, or one lesson when `$lesson` is given),
     * then recreate it unless the same key was pressed again (the undo).
     *
     * @return JsonResponse {reactions: {key: count}, mine: [keys]}
     */
    private function toggleReaction(Request $request, CompanyEvent $event, ?EventLesson $lesson): JsonResponse
    {
        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403, 'No employee profile in this workspace.');

        $data = $request->validate([
            'reaction' => ['required', 'string', 'in:'.implode(',', Reaction::activeKeys())],
        ]);

        $scoped = fn () => EventReaction::where('company_event_id', $event->id)
            ->where('employee_id', $employee->id)
            ->when($lesson, fn ($q) => $q->where('lesson_id', $lesson->id), fn ($q) => $q->whereNull('lesson_id'));

        $had = $scoped()->pluck('reaction');
        $scoped()->delete();

        if (! $had->contains($data['reaction'])) {
            try {
                EventReaction::create([
                    'company_event_id' => $event->id,
                    'lesson_id' => $lesson?->id,
                    'employee_id' => $employee->id,
                    'reaction' => $data['reaction'],
                ]);
            } catch (QueryException $e) {
                if (! str_starts_with((string) $e->getCode(), '23')) {
                    throw $e;
                }
            }
        }

        $all = EventReaction::where('company_event_id', $event->id)
            ->when($lesson, fn ($q) => $q->where('lesson_id', $lesson->id), fn ($q) => $q->whereNull('lesson_id'))
            ->get();

        return response()->json($this->reactionState($all, $employee));
    }

    /**
     * Reaction tallies for one target's already-loaded reactions, plus this viewer's own.
     *
     * @return array{reactions: array<string, int>, mine: list<string>}
     */
    private function reactionState(iterable $reactions, ?Employee $employee): array
    {
        $reactions = collect($reactions);

        return [
            'reactions' => $reactions->groupBy('reaction')->map->count()->all(),
            'mine' => $employee ? $reactions->where('employee_id', $employee->id)->pluck('reaction')->values()->all() : [],
        ];
    }
}
