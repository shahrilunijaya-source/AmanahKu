<?php

namespace Tests\Feature;

use App\Jobs\CalendarFullSyncJob;
use App\Models\CompanyEvent;
use App\Models\CompanyEventCalendarCopy;
use App\Models\Employee;
use App\Models\GoogleCalendarConnection;
use App\Models\Tenant;
use App\Models\User;
use App\Ports\CalendarPort;
use App\Ports\Data\CalendarEvent;
use App\Support\Calendar\CalendarReconciler;
use App\Support\Calendar\CalendarSyncStatus;
use App\Support\Calendar\CompanyEventCopies;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Every upcoming company event goes into every connected employee's Google Calendar, RSVP'd or not. */
class CompanyEventCalendarCopiesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Employee $poster;

    private RecordingCalendarPort $port;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $this->poster = $this->person('Poster', 'poster@example.com');
        $this->port = new RecordingCalendarPort;
        $this->app->instance(CalendarPort::class, $this->port);
        app(CurrentTenant::class)->set($this->tenant);
    }

    protected function tearDown(): void
    {
        app(CurrentTenant::class)->set(null);
        parent::tearDown();
    }

    private function person(string $name, string $email, bool $connected = true): Employee
    {
        $user = User::create(['name' => $name, 'email' => $email, 'password' => Hash::make('password')]);
        $user->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        if ($connected) {
            GoogleCalendarConnection::create(['user_id' => $user->id, 'access_token' => 't', 'refresh_token' => 'r', 'expires_at' => now()->addHour()]);
        }

        return Employee::create(['tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'name' => $name, 'status' => 'active', 'workload' => 'green']);
    }

    private function event(array $attrs = []): CompanyEvent
    {
        return CompanyEvent::create($attrs + [
            'tenant_id' => $this->tenant->id,
            'created_by_employee_id' => $this->poster->id,
            'title' => 'Family Day',
            'type' => 'social',
            'event_date' => now()->addDays(10)->toDateString(),
        ]);
    }

    public function test_a_connected_employee_gets_a_copy_when_the_event_is_posted(): void
    {
        $event = $this->event();

        CompanyEventCopies::sync($event);

        $this->assertSame([$this->poster->id], $this->port->pushedTo());
        $this->assertDatabaseHas('company_event_calendar_copies', ['company_event_id' => $event->id, 'employee_id' => $this->poster->id]);
    }

    public function test_an_unconnected_employee_gets_no_copy(): void
    {
        $offline = $this->person('Offline', 'offline@example.com', connected: false);
        $event = $this->event();

        CompanyEventCopies::sync($event);

        $this->assertNotContains($offline->id, $this->port->pushedTo());
    }

    public function test_someone_with_an_active_event_card_is_not_a_recipient(): void
    {
        $attendee = $this->person('Attendee', 'attendee@example.com');
        $event = $this->event();
        $attendee->workItems()->create([
            'tenant_id' => $this->tenant->id, 'title' => $event->title, 'type' => 'event',
            'priority' => 'medium', 'status' => 'todo', 'progress' => 0,
            'due_at' => $event->event_date, 'company_event_id' => $event->id,
        ]);

        $ids = CompanyEventCopies::recipients($event)->pluck('id')->all();

        $this->assertNotContains($attendee->id, $ids);
        $this->assertContains($this->poster->id, $ids);
    }

    public function test_updating_the_event_re_pushes_the_same_google_id(): void
    {
        $event = $this->event();
        CompanyEventCopies::sync($event);
        $copy = CompanyEventCalendarCopy::where('company_event_id', $event->id)->where('employee_id', $this->poster->id)->first();
        $firstId = $copy->google_event_id;
        $this->port->upserts = [];

        $event->update(['title' => 'Family Day 2026']);
        CompanyEventCopies::sync($event);

        $this->assertSame([$this->poster->id], $this->port->pushedTo());
        $this->assertSame($firstId, $this->port->upserts[0]['event']->externalId);
        $this->assertSame('Family Day 2026', $this->port->upserts[0]['event']->title);
    }

    public function test_removing_the_event_sends_a_delete_for_every_copy(): void
    {
        $event = $this->event();
        CompanyEventCopies::sync($event);
        $eventId = CompanyEventCalendarCopy::value('google_event_id');

        CompanyEventCopies::removeAll($event);

        $this->assertSame([['employee' => $this->poster->id, 'id' => $eventId]], $this->port->deletes);
        $this->assertDatabaseCount('company_event_calendar_copies', 0);
    }

    public function test_rsvp_going_removes_the_copy_and_withdrawal_brings_it_back(): void
    {
        $event = $this->event();
        CompanyEventCopies::sync($event);
        $this->assertDatabaseHas('company_event_calendar_copies', ['company_event_id' => $event->id, 'employee_id' => $this->poster->id]);
        $eventId = CompanyEventCalendarCopy::value('google_event_id');

        // Becomes an attendee (an event card exists): no longer a copy recipient.
        $card = $this->poster->workItems()->create([
            'tenant_id' => $this->tenant->id, 'title' => $event->title, 'type' => 'event',
            'priority' => 'medium', 'status' => 'todo', 'progress' => 0,
            'due_at' => $event->event_date, 'company_event_id' => $event->id,
        ]);
        CompanyEventCopies::sync($event);

        $this->assertContains(['employee' => $this->poster->id, 'id' => $eventId], $this->port->deletes);
        $this->assertDatabaseCount('company_event_calendar_copies', 0);

        // Withdrawal (card archived): a recipient again.
        $card->update(['archived_at' => now()]);
        $this->port->upserts = [];
        CompanyEventCopies::sync($event);

        $this->assertSame([$this->poster->id], $this->port->pushedTo());
    }

    public function test_full_sync_targets_include_upcoming_events_and_skip_past_ones(): void
    {
        $upcoming = $this->event();
        $past = $this->event(['event_date' => now()->subDays(5)->toDateString(), 'title' => 'Old Event']);

        $ref = new \ReflectionMethod(CalendarFullSyncJob::class, 'targets');
        $ref->setAccessible(true);
        $job = new CalendarFullSyncJob($this->poster->user_id);
        $targets = $ref->invoke($job);

        $eventTargets = array_filter($targets, fn ($t) => $t['kind'] === 'event');
        $ids = array_column($eventTargets, 'id');

        $this->assertContains($upcoming->id, $ids);
        $this->assertNotContains($past->id, $ids);
    }

    public function test_reconciler_ignores_the_echo_of_its_own_push_and_repushes_a_cancelled_copy(): void
    {
        $event = $this->event();
        CompanyEventCopies::sync($event);
        $copy = CompanyEventCalendarCopy::where('company_event_id', $event->id)->where('employee_id', $this->poster->id)->first();

        $reconciler = app(CalendarReconciler::class);

        // Echo: same version, no new push.
        $this->port->upserts = [];
        $reconciler->reconcile($this->poster, [new CalendarEvent(
            title: 'echo', startsAt: now()->toImmutable(), endsAt: now()->addHour()->toImmutable(),
            externalId: $copy->google_event_id, version: $copy->calendar_version,
        )]);
        $this->assertSame([], $this->port->upserts);

        // Cancelled in Google: cleared then re-pushed.
        $reconciler->reconcile($this->poster, [new CalendarEvent(
            title: 'cancelled', startsAt: now()->toImmutable(), endsAt: now()->addHour()->toImmutable(),
            externalId: $copy->google_event_id, cancelled: true,
        )]);
        $this->assertSame([$this->poster->id], $this->port->pushedTo());
    }

    public function test_status_lists_event_issues_with_their_kind(): void
    {
        $event = $this->event();
        CompanyEventCalendarCopy::create([
            'tenant_id' => $this->tenant->id, 'company_event_id' => $event->id,
            'employee_id' => $this->poster->id, 'sync_error' => 'Google said no',
        ]);

        $status = CalendarSyncStatus::for($this->poster->user, $this->tenant->id);

        $issue = collect($status['issues'])->firstWhere('id', 'event-'.$event->id);
        $this->assertNotNull($issue);
        $this->assertSame('event', $issue['kind']);
        $this->assertSame($event->title, $issue['title']);
    }
}
