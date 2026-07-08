<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\CompanyEvent;
use App\Models\Employee;
use App\Models\EventRsvp;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EventController extends Controller
{
    /** HR/management may publish company events; everyone may RSVP. */
    private const PRIVILEGED_ROLES = ['management', 'hr'];

    private const TYPES = ['townhall', 'training', 'holiday', 'social', 'meeting'];

    private const RESPONSES = ['going', 'maybe', 'declined'];

    /**
     * Everyone sees upcoming events with RSVP counts and their own choice per event.
     * Privileged roles additionally receive a create-form flag and past events.
     * Counts are aggregated in PHP to stay DB-agnostic and rely on the
     * BelongsToTenant scope for tenant isolation.
     */
    public function screenData(Request $request, ?Employee $employee): array
    {
        $role = $request->attributes->get('tenantRole', 'employee');
        $privileged = in_array($role, self::PRIVILEGED_ROLES, true);

        $today = now()->toDateString();

        $upcoming = CompanyEvent::with('rsvps')
            ->whereDate('event_date', '>=', $today)
            ->orderBy('event_date')
            ->orderBy('start_time')
            ->get()
            ->map(fn (CompanyEvent $event) => $this->present($event, $employee));

        $past = $privileged
            ? CompanyEvent::with('rsvps')
                ->whereDate('event_date', '<', $today)
                ->orderByDesc('event_date')
                ->take(10)
                ->get()
                ->map(fn (CompanyEvent $event) => $this->present($event, $employee))
            : collect();

        return [
            'privileged' => $privileged,
            'canRespond' => (bool) $employee,
            'upcomingEvents' => $upcoming,
            'pastEvents' => $past,
            'eventTypes' => self::TYPES,
        ];
    }

    /** Privileged-only: publish a new company event. */
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
        ]);

        $event = CompanyEvent::create([
            'tenant_id' => app(CurrentTenant::class)->id(),
            'title' => $data['title'],
            'type' => $data['type'],
            'event_date' => $data['event_date'],
            'start_time' => $data['start_time'] ?? null,
            'location' => $data['location'] ?? null,
            'description' => $data['description'] ?? null,
            'created_by_employee_id' => $request->attributes->get('employee')?->id,
        ]);

        AuditLog::record('Created event', $event->title);

        return back()->with('ok', 'Event "'.$event->title.'" published.');
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
            'Only HR and management can create events.'
        );
    }
}
