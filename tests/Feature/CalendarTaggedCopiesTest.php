<?php

namespace Tests\Feature;

use App\Jobs\SyncWorkItemCalendarEventJob;
use App\Models\CompanyEvent;
use App\Models\Employee;
use App\Models\GoogleCalendarConnection;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkItemCalendarCopy;
use App\Ports\CalendarPort;
use App\Ports\Data\CalendarEvent;
use App\Ports\PortResult;
use App\Support\Calendar\TaggedCopies;
use App\Tenancy\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Tagged Helper/FYI people get their own copy of a card in their Google Calendar. */
class CalendarTaggedCopiesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Employee $owner;

    private Employee $helper;

    private RecordingCalendarPort $port;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $this->owner = $this->person('Owner', 'owner@example.com');
        $this->helper = $this->person('Helper', 'helper@example.com');
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

    private function card(array $attrs = []): WorkItem
    {
        return $this->owner->workItems()->create($attrs + [
            'tenant_id' => $this->tenant->id, 'title' => 'Budget', 'type' => 'task',
            'priority' => 'medium', 'status' => 'todo', 'progress' => 0, 'due_at' => '2026-10-05',
        ]);
    }

    public function test_recipients_are_helpers_and_fyi_but_not_the_owner_or_a_reviewer(): void
    {
        $fyi = $this->person('Fyi', 'fyi@example.com');
        $reviewer = $this->person('Reviewer', 'reviewer@example.com');
        $card = $this->card(['reviewer_id' => $reviewer->id]);
        $card->participants()->attach([
            $this->helper->id => ['role' => 'helper'],
            $fyi->id => ['role' => 'fyi'],
            $this->owner->id => ['role' => 'helper'],
        ]);

        $ids = TaggedCopies::recipients($card->fresh())->pluck('id')->sort()->values()->all();

        $this->assertSame(collect([$this->helper->id, $fyi->id])->sort()->values()->all(), $ids);
    }

    public function test_company_event_cards_have_no_tagged_recipients(): void
    {
        $companyEvent = CompanyEvent::create([
            'tenant_id' => $this->tenant->id,
            'title' => 'Family Day', 'type' => 'social',
            'event_date' => now()->addDays(10)->toDateString(),
        ]);
        $card = $this->card(['type' => 'event']);
        WorkItem::withoutGlobalScopes()->where('id', $card->id)->update(['company_event_id' => $companyEvent->id]);
        $card->participants()->attach($this->helper->id, ['role' => 'helper']);

        $this->assertCount(0, TaggedCopies::recipients($card->fresh()));
    }

    public function test_a_connection_can_be_marked_revoked(): void
    {
        $connection = GoogleCalendarConnection::where('user_id', $this->helper->user_id)->first();
        $connection->forceFill(['revoked_at' => now()])->save();

        $this->assertNotNull($connection->fresh()->revoked_at);
    }

    private function runJob(string $action, WorkItem $card, ?Employee $recipient = null, ?string $eventId = null): void
    {
        (new SyncWorkItemCalendarEventJob(
            tenantId: $this->tenant->id, action: $action, workItemId: $card->id,
            userId: $recipient?->user_id, googleEventId: $eventId, recipientEmployeeId: $recipient?->id,
        ))->handle(app(CurrentTenant::class), app(CalendarPort::class));
    }

    public function test_upsert_for_a_tagged_recipient_writes_their_copy_not_the_card(): void
    {
        $card = $this->card();
        WorkItem::withoutGlobalScopes()->where('id', $card->id)->update(['google_event_id' => 'owner-evt']);
        DB::table('work_item_participant')->insert(['work_item_id' => $card->id, 'employee_id' => $this->helper->id, 'role' => 'helper']);
        $this->port->upserts = [];

        $this->runJob('upsert', $card, $this->helper);

        $this->assertSame([$this->helper->id], $this->port->pushedTo());
        $this->assertNull($this->port->upserts[0]['event']->externalId, 'a copy never reuses the owner event id');
        $copy = WorkItemCalendarCopy::where('work_item_id', $card->id)->where('employee_id', $this->helper->id)->first();
        $this->assertStringStartsWith("evt-{$this->helper->id}-", $copy->google_event_id);
        $this->assertSame('owner-evt', $card->fresh()->google_event_id);
    }

    public function test_upsert_for_someone_no_longer_tagged_does_nothing(): void
    {
        $card = $this->card();
        $this->port->upserts = [];

        $this->runJob('upsert', $card, $this->helper);

        $this->assertSame([], $this->port->upserts);
    }

    public function test_delete_for_a_recipient_removes_their_copy_only(): void
    {
        $card = $this->card();
        WorkItem::withoutGlobalScopes()->where('id', $card->id)->update(['google_event_id' => 'owner-evt']);
        WorkItemCalendarCopy::create(['tenant_id' => $this->tenant->id, 'work_item_id' => $card->id, 'employee_id' => $this->helper->id, 'google_event_id' => 'copy-evt']);

        $this->runJob('delete', $card, $this->helper, 'copy-evt');

        $this->assertSame([['employee' => $this->helper->id, 'id' => 'copy-evt']], $this->port->deletes);
        $this->assertDatabaseMissing('work_item_calendar_copies', ['work_item_id' => $card->id, 'employee_id' => $this->helper->id]);
        $this->assertSame('owner-evt', $card->fresh()->google_event_id);
    }

    public function test_giving_up_on_a_copy_records_the_error_on_the_copy(): void
    {
        $card = $this->card();
        WorkItemCalendarCopy::create(['tenant_id' => $this->tenant->id, 'work_item_id' => $card->id, 'employee_id' => $this->helper->id]);

        (new SyncWorkItemCalendarEventJob(tenantId: $this->tenant->id, action: 'upsert', workItemId: $card->id, recipientEmployeeId: $this->helper->id))
            ->failed(new \RuntimeException('Google said no'));

        $this->assertSame('Google said no', WorkItemCalendarCopy::first()->sync_error);
        $this->assertNull($card->fresh()->calendar_sync_error);
    }

    public function test_a_revoked_connection_is_not_pushed_to(): void
    {
        GoogleCalendarConnection::where('user_id', $this->owner->user_id)->update(['revoked_at' => now()]);
        $this->port->upserts = [];

        $card = $this->card();
        $this->runJob('upsert', $card);

        $this->assertSame([], $this->port->upserts);
    }
}

/** Records every call; each push gets a fresh id and version. */
final class RecordingCalendarPort implements CalendarPort
{
    /** @var list<array{employee: int, event: CalendarEvent}> */
    public array $upserts = [];

    /** @var list<array{employee: int, id: string}> */
    public array $deletes = [];

    /** @var list<CalendarEvent> */
    public array $pending = [];

    public bool $fail = false;

    public function upsertEvent(Employee $for, CalendarEvent $event): PortResult
    {
        $this->upserts[] = ['employee' => $for->id, 'event' => $event];
        $n = count($this->upserts);

        return new PortResult(ok: ! $this->fail, externalId: $event->externalId ?? "evt-{$for->id}-{$n}", payload: ['version' => "v{$n}"], outboxId: $n);
    }

    public function deleteEvent(Employee $for, string $externalId): PortResult
    {
        $this->deletes[] = ['employee' => $for->id, 'id' => $externalId];

        return new PortResult(ok: true, externalId: $externalId, payload: [], outboxId: 0);
    }

    public function pullChanges(Employee $for, CarbonImmutable $since): PortResult
    {
        $changes = $this->pending;
        $this->pending = [];

        return new PortResult(ok: true, externalId: null, payload: $changes, outboxId: 0);
    }

    /** @return list<int> employee ids that received a push */
    public function pushedTo(): array
    {
        return array_column($this->upserts, 'employee');
    }
}
