<?php

namespace Tests\Feature;

use App\Jobs\PullCalendarChangesJob;
use App\Jobs\SyncWorkItemCalendarEventJob;
use App\Models\Employee;
use App\Models\GoogleCalendarConnection;
use App\Models\PortOutbox;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkItemComment;
use App\Ports\CalendarPort;
use App\Ports\Data\CalendarEvent;
use App\Ports\PortResult;
use App\Support\Calendar\CalendarReconciler;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * CR-01 acceptance, driven through a fake CalendarPort: what a Google-side change does
 * to a card, and what a card change sends to the calendar. Nothing here talks to Google.
 */
class CalendarTwoWaySyncTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    private Employee $employee;

    private FakeCalendarPort $port;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $this->user = User::create(['name' => 'Aisyah', 'email' => 'aisyah@example.com', 'password' => Hash::make('password')]);
        $this->user->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $this->employee = Employee::create(['tenant_id' => $this->tenant->id, 'user_id' => $this->user->id, 'name' => 'Aisyah', 'status' => 'active', 'workload' => 'green']);
        GoogleCalendarConnection::create(['user_id' => $this->user->id, 'access_token' => 'tok', 'refresh_token' => 'ref', 'expires_at' => now()->addHour()]);

        $this->port = new FakeCalendarPort;
        $this->app->instance(CalendarPort::class, $this->port);
        app(CurrentTenant::class)->set($this->tenant);
    }

    protected function tearDown(): void
    {
        app(CurrentTenant::class)->set(null);
        parent::tearDown();
    }

    private function card(array $attrs = []): WorkItem
    {
        return $this->employee->workItems()->create($attrs + [
            'tenant_id' => $this->tenant->id, 'title' => 'Write the spec', 'type' => 'task',
            'priority' => 'medium', 'status' => 'todo', 'progress' => 0, 'due_at' => '2026-10-05',
        ]);
    }

    private function pull(CalendarEvent ...$changes): void
    {
        app(CalendarReconciler::class)->reconcile($this->employee, $changes);
    }

    // 1. Set a due date on a card, the event goes to the calendar.
    public function test_acceptance_1_a_dated_card_is_pushed_to_the_calendar(): void
    {
        $card = $this->card(['due_at' => null]);
        $this->assertSame([], $this->port->upserts);

        $card->update(['due_at' => '2026-10-05']);

        $this->assertCount(1, $this->port->upserts);
        $this->assertSame('Write the spec', $this->port->upserts[0]->title);
        $this->assertSame('2026-10-05', $this->port->upserts[0]->startsAt->toDateString());
        $this->assertSame('fake-1', $card->fresh()->google_event_id);
        $this->assertSame('v1', $card->fresh()->calendar_version);
    }

    // 2. Change the date in Google Calendar, the Event card's due date follows.
    public function test_acceptance_2_a_calendar_move_reschedules_an_event_card(): void
    {
        $card = $this->card(['type' => 'event', 'google_event_id' => 'evt-1', 'calendar_version' => 'v1', 'updated_at' => now()->subHour()]);
        WorkItem::withoutGlobalScopes()->where('id', $card->id)->update(['updated_at' => now()->subHour()]);

        $this->pull(new CalendarEvent('Write the spec', CarbonImmutable::parse('2026-10-18', 'Asia/Kuala_Lumpur'), CarbonImmutable::parse('2026-10-19', 'Asia/Kuala_Lumpur'), externalId: 'evt-1', allDay: true, version: 'v2'));

        $fresh = $card->fresh();
        $this->assertSame('2026-10-18', $fresh->due_at->toDateString());
        $this->assertSame('v2', $fresh->calendar_version);
        $this->assertStringContainsString('Rescheduled from 5 Oct 2026 to 18 Oct 2026 via Google Calendar', WorkItemComment::where('work_item_id', $card->id)->latest('id')->value('body'));
    }

    // 3. Delete the event in Google, the Event card shows Cancelled (calendar) and still exists.
    public function test_acceptance_3_a_calendar_delete_cancels_an_event_card_but_keeps_it(): void
    {
        $card = $this->card(['type' => 'event', 'google_event_id' => 'evt-1', 'calendar_version' => 'v1']);

        $this->pull(new CalendarEvent('Write the spec', CarbonImmutable::parse('2026-10-05'), CarbonImmutable::parse('2026-10-06'), externalId: 'evt-1', cancelled: true, version: 'v2'));

        $fresh = WorkItem::withoutGlobalScopes()->find($card->id);
        $this->assertNotNull($fresh);
        $this->assertNotNull($fresh->cancelled_at);
        $this->assertSame('Closed automatically – Cancelled (calendar)', WorkItemComment::where('work_item_id', $card->id)->latest('id')->value('body'));
        $this->assertDatabaseHas('app_notifications', ['user_id' => $this->user->id, 'title' => 'Cancelled in Google Calendar: Write the spec']);
    }

    // 4. Disconnect then reconnect: the same event id is reused, no duplicate events or cards.
    public function test_acceptance_4_reconnecting_reuses_the_stored_ids_and_makes_no_duplicates(): void
    {
        $card = $this->card();
        $this->assertSame('fake-1', $card->fresh()->google_event_id);

        GoogleCalendarConnection::where('user_id', $this->user->id)->delete();
        GoogleCalendarConnection::create(['user_id' => $this->user->id, 'access_token' => 'tok2', 'refresh_token' => 'ref2', 'expires_at' => now()->addHour()]);
        $card->fresh()->update(['title' => 'Write the spec v2']);

        $this->assertCount(2, $this->port->upserts);
        $this->assertSame('fake-1', $this->port->upserts[1]->externalId);
        $this->assertSame('fake-1', $card->fresh()->google_event_id);
        $this->assertSame(1, WorkItem::withoutGlobalScopes()->where('google_event_id', 'fake-1')->count());

        // Our own push echoing back through the pull makes nothing happen.
        $this->pull(new CalendarEvent('Write the spec v2', CarbonImmutable::parse('2026-10-05'), CarbonImmutable::parse('2026-10-06'), externalId: 'fake-1', version: 'v2'));
        $this->assertSame(1, WorkItem::withoutGlobalScopes()->count());
        $this->assertCount(2, $this->port->upserts);
    }

    // 5. Edit a title five times quickly: one queued push, one event, no ping-pong.
    public function test_acceptance_5_five_quick_title_edits_collapse_into_one_push(): void
    {
        Queue::fake();
        $card = $this->card();

        foreach (range(1, 5) as $i) {
            $card->update(['title' => "Write the spec {$i}"]);
        }

        // Six dispatches (create + five edits) share one unique id, so the queue holds one job.
        Queue::assertPushed(SyncWorkItemCalendarEventJob::class, 1);
        $this->assertTrue(in_array(ShouldBeUniqueUntilProcessing::class, class_implements(SyncWorkItemCalendarEventJob::class)));
    }

    // 6. A personal event on the primary calendar never reaches the port, so no card is created;
    //    an event on the Amanahku calendar becomes an Event card the person can convert.
    public function test_acceptance_6_only_amanahku_calendar_events_become_cards(): void
    {
        $this->pull(new CalendarEvent('Client discussion', CarbonImmutable::parse('2026-10-07 10:00', 'Asia/Kuala_Lumpur'), CarbonImmutable::parse('2026-10-07 11:00', 'Asia/Kuala_Lumpur'), externalId: 'goog-9', version: 'v1'));

        $imported = WorkItem::withoutGlobalScopes()->where('google_event_id', 'goog-9')->sole();
        $this->assertSame('event', $imported->type);
        $this->assertSame('2026-10-07', $imported->due_at->toDateString());
        $this->assertSame($this->employee->id, $imported->employee_id);

        // The client only ever lists the Amanahku calendar (rule 1); the primary is not a source.
        $this->assertStringNotContainsString('calendars/primary', file_get_contents(app_path('Services/GoogleCalendarClient.php')));
    }

    // 7. Move a Task's calendar entry: due date unchanged, the entry snaps back.
    public function test_acceptance_7_moving_a_task_entry_keeps_the_locked_date_and_snaps_back(): void
    {
        $card = $this->card(['google_event_id' => 'evt-1', 'calendar_version' => 'v1']);
        $pushes = count($this->port->upserts);

        $this->pull(new CalendarEvent('Write the spec', CarbonImmutable::parse('2026-10-20'), CarbonImmutable::parse('2026-10-21'), externalId: 'evt-1', allDay: true, version: 'v2'));

        $this->assertSame('2026-10-05', $card->fresh()->due_at->toDateString());
        $this->assertCount($pushes + 1, $this->port->upserts);
        $this->assertSame('2026-10-05', end($this->port->upserts)->startsAt->toDateString());
        $this->assertStringContainsString('due date stays 5 Oct 2026', WorkItemComment::where('work_item_id', $card->id)->latest('id')->value('body'));
    }

    public function test_deleting_a_task_entry_in_the_calendar_recreates_it_and_touches_nothing_else(): void
    {
        $card = $this->card(['google_event_id' => 'evt-1', 'calendar_version' => 'v1']);

        $this->pull(new CalendarEvent('Write the spec', CarbonImmutable::parse('2026-10-05'), CarbonImmutable::parse('2026-10-06'), externalId: 'evt-1', cancelled: true, version: 'v2'));

        $fresh = $card->fresh();
        $this->assertNull($fresh->cancelled_at);
        $this->assertSame('2026-10-05', $fresh->due_at->toDateString());
        $this->assertNotNull($fresh->google_event_id, 'a fresh push replaced the deleted entry');
    }

    public function test_an_event_moved_on_both_sides_within_a_minute_keeps_amanahku_and_tells_the_owner(): void
    {
        $card = $this->card(['type' => 'event', 'google_event_id' => 'evt-1', 'calendar_version' => 'v1']);

        $this->pull(new CalendarEvent('Write the spec', CarbonImmutable::parse('2026-10-18'), CarbonImmutable::parse('2026-10-19'), externalId: 'evt-1', version: 'v2'));

        $this->assertSame('2026-10-05', $card->fresh()->due_at->toDateString());
        $this->assertDatabaseHas('app_notifications', ['user_id' => $this->user->id, 'title' => 'Calendar conflict on Write the spec']);
    }

    public function test_the_pull_job_asks_the_port_per_tenant_and_stamps_the_connection(): void
    {
        $connection = GoogleCalendarConnection::where('user_id', $this->user->id)->sole();
        $this->port->pending = [new CalendarEvent('Client discussion', CarbonImmutable::parse('2026-10-07'), CarbonImmutable::parse('2026-10-08'), externalId: 'goog-9', version: 'v1')];

        (new PullCalendarChangesJob($connection->id))->handle(app(CurrentTenant::class), $this->port, app(CalendarReconciler::class));

        $this->assertSame([$this->employee->id], $this->port->pulls);
        $this->assertNotNull($connection->fresh()->last_pulled_at);
        $this->assertSame(1, WorkItem::withoutGlobalScopes()->where('google_event_id', 'goog-9')->count());
    }

    public function test_the_stub_port_records_a_pull_intent_and_returns_nothing(): void
    {
        $this->app->forgetInstance(CalendarPort::class);
        $stub = app(CalendarPort::class);

        $result = $stub->pullChanges($this->employee, CarbonImmutable::now());

        $this->assertTrue($result->ok);
        $this->assertSame([], $result->payload);
        $this->assertSame('pullChanges', PortOutbox::latest('id')->value('method'));
    }

    public function test_a_card_that_gave_up_lists_under_sync_issues_and_can_be_retried(): void
    {
        Queue::fake();
        $card = $this->card();
        WorkItem::withoutGlobalScopes()->where('id', $card->id)->update(['calendar_sync_error' => 'Calendar push failed (outbox #3).']);

        config(['services.google_calendar.client_id' => 'client-123', 'services.google_calendar.client_secret' => 'secret-456']);
        $this->actingAs($this->user)->withSession(['current_tenant' => $this->tenant->id])->get('/app/profile')
            ->assertOk()->assertSee('Sync issues')->assertSee('Write the spec');

        $this->actingAs($this->user)->withSession(['current_tenant' => $this->tenant->id])->post(route('google-calendar.retry', $card))->assertRedirect('/app/profile');
        $this->assertNull($card->fresh()->calendar_sync_error);
        Queue::assertPushed(SyncWorkItemCalendarEventJob::class);
    }
}

/** Answers every push with a stable id and a version stamp, and hands back whatever `pending` holds on pull. */
final class FakeCalendarPort implements CalendarPort
{
    /** @var list<CalendarEvent> */
    public array $upserts = [];

    /** @var list<string> */
    public array $deletes = [];

    /** @var list<int> */
    public array $pulls = [];

    /** @var list<CalendarEvent> */
    public array $pending = [];

    public function upsertEvent(Employee $for, CalendarEvent $event): PortResult
    {
        $this->upserts[] = $event;
        $id = $event->externalId ?? 'fake-'.count($this->upserts);

        return new PortResult(ok: true, externalId: $id, payload: ['version' => 'v'.count($this->upserts)], outboxId: count($this->upserts));
    }

    public function deleteEvent(Employee $for, string $externalId): PortResult
    {
        $this->deletes[] = $externalId;

        return new PortResult(ok: true, externalId: $externalId, payload: [], outboxId: 0);
    }

    public function pullChanges(Employee $for, CarbonImmutable $since): PortResult
    {
        $this->pulls[] = $for->id;
        $changes = $this->pending;
        $this->pending = [];

        return new PortResult(ok: true, externalId: null, payload: $changes, outboxId: 0);
    }
}
