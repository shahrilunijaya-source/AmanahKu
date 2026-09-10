<?php

namespace Tests\Feature;

use App\Jobs\SyncWorkItemCalendarEventJob;
use App\Models\Employee;
use App\Models\GoogleCalendarConnection;
use App\Models\PortOutbox;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkItem;
use App\Ports\CalendarPort;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** The push job goes through CalendarPort (CR-01): the stub records the intent in port_outbox, nothing leaves. */
class SyncWorkItemCalendarEventJobTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $assigneeUser;

    private Employee $assignee;

    private WorkItem $item;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $this->assigneeUser = User::create(['name' => 'Assignee', 'email' => 'assignee@example.com', 'password' => Hash::make('password')]);
        $this->assigneeUser->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $this->assignee = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->assigneeUser->id,
            'name' => 'Assignee', 'status' => 'active', 'workload' => 'green',
        ]);
        $this->item = $this->assignee->workItems()->create([
            'tenant_id' => $this->tenant->id, 'title' => 'Ship it', 'type' => 'task',
            'priority' => 'low', 'status' => 'todo', 'progress' => 0, 'due_at' => '2026-09-30',
        ]);
    }

    private function connectAssignee(): GoogleCalendarConnection
    {
        return GoogleCalendarConnection::create([
            'user_id' => $this->assigneeUser->id, 'access_token' => 'tok', 'refresh_token' => 'ref', 'expires_at' => now()->addHour(),
        ]);
    }

    private function runJob(string $action, ?int $userId = null, ?string $eventId = null): void
    {
        (new SyncWorkItemCalendarEventJob(
            tenantId: $this->tenant->id, action: $action, workItemId: $this->item->id, userId: $userId, googleEventId: $eventId,
        ))->handle(app(CurrentTenant::class), app(CalendarPort::class));
    }

    public function test_upsert_writes_the_intent_and_stores_the_external_id(): void
    {
        $this->connectAssignee();

        $this->runJob('upsert');

        $row = PortOutbox::where('method', 'upsertEvent')->sole();
        $this->assertSame('sent', $row->status);
        $this->assertSame($this->item->id, (int) $row->subject_id);
        $this->assertSame('Ship it', $row->payload['title']);
        $this->assertStringContainsString('Type: Task', $row->payload['description']);
        $this->assertStringContainsString("/app/board/{$this->item->id}", $row->payload['description']);
        $this->assertSame($row->external_id, $this->item->fresh()->google_event_id);
        Http::assertNothingSent();
    }

    public function test_upsert_is_a_no_op_when_the_assignee_has_no_connection(): void
    {
        $this->runJob('upsert');

        $this->assertSame(0, PortOutbox::count());
        $this->assertNull($this->item->fresh()->google_event_id);
    }

    public function test_upsert_is_a_no_op_when_the_item_is_no_longer_syncable(): void
    {
        $this->connectAssignee();
        $this->item->update(['status' => 'done', 'done_at' => now()]);
        PortOutbox::query()->delete();

        $this->runJob('upsert');

        $this->assertSame(0, PortOutbox::count());
    }

    public function test_delete_sends_the_intent_and_clears_the_column(): void
    {
        $this->connectAssignee();
        $this->item->update(['google_event_id' => 'evt_old']);

        $this->runJob('delete', $this->assigneeUser->id, 'evt_old');

        $row = PortOutbox::where('method', 'deleteEvent')->sole();
        $this->assertSame('evt_old', $row->payload['external_id']);
        $this->assertNull($this->item->fresh()->google_event_id);
    }

    public function test_delete_is_a_no_op_without_a_connection(): void
    {
        $this->runJob('delete', $this->assigneeUser->id, 'evt_old');

        $this->assertSame(0, PortOutbox::count());
    }

    public function test_handle_restores_the_previous_tenant_context(): void
    {
        $this->connectAssignee();
        $context = app(CurrentTenant::class);

        $this->runJob('upsert');

        $this->assertFalse($context->check());
    }

    public function test_giving_up_records_the_error_on_the_card_for_the_sync_issues_list(): void
    {
        (new SyncWorkItemCalendarEventJob(tenantId: $this->tenant->id, action: 'upsert', workItemId: $this->item->id))
            ->failed(new \RuntimeException('Calendar push failed (outbox #9).'));

        $this->assertSame('Calendar push failed (outbox #9).', $this->item->fresh()->calendar_sync_error);
    }
}
