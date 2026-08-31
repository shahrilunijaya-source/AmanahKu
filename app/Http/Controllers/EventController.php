<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AppNotification;
use App\Models\AuditLog;
use App\Models\CompanyEvent;
use App\Models\Employee;
use App\Models\EventRsvp;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class EventController extends Controller
{
    /**
     * HR/management may publish company events; everyone may RSVP. A manager is one
     * tier wider than the internal roster's privileged pair, because a manager is often
     * the one actually forwarded an external invite and shouldn't have to route it
     * through HR first.
     */
    private const PRIVILEGED_ROLES = ['manager', 'management', 'hr'];

    private const TYPES = ['townhall', 'training', 'holiday', 'social', 'meeting'];

    private const RESPONSES = ['going', 'maybe', 'declined'];

    /** How far back "recent" past events reach before an older event is collapsed. */
    private const RECENT_PAST_DAYS = 30;

    /** How many older-than-recent past events the collapsed bucket shows at most. */
    private const OLDER_PAST_LIMIT = 20;

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
     * PRIVILEGED_ROLES trio can post either kind.
     */
    public function store(Request $request): RedirectResponse
    {
        $this->authorizePrivileged($request);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'type' => ['required', 'in:'.implode(',', self::TYPES)],
            'event_date' => ['required', 'date'],
            'start_time' => ['nullable', 'string', 'max:40'],
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
     * says.
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

    /** Any employee may RSVP once per event; submitting again updates the same row. */
    public function rsvp(Request $request, CompanyEvent $event): RedirectResponse
    {
        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403, 'No employee profile in this workspace.');
        abort_unless($event->tenant_id === app(CurrentTenant::class)->id(), 403);

        $data = $request->validate([
            'response' => ['required', 'in:'.implode(',', self::RESPONSES)],
        ]);

        // updateOrCreate keyed on (event, employee) respects the unique constraint —
        // a second RSVP updates the existing row rather than inserting a duplicate.
        EventRsvp::updateOrCreate(
            [
                'company_event_id' => $event->id,
                'employee_id' => $employee->id,
            ],
            [
                'tenant_id' => $event->tenant_id,
                'response' => $data['response'],
            ],
        );

        return back()->with('ok', 'Your RSVP was recorded.');
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

    private function authorizePrivileged(Request $request): void
    {
        abort_unless(
            $this->hasTenantRole($request, self::PRIVILEGED_ROLES),
            403,
            'Only managers, HR and management can create events.'
        );
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
}
