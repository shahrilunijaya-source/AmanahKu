<?php

namespace Tests\Feature;

use App\Jobs\SyncWorkItemCalendarEventJob;
use App\Models\Employee;
use App\Models\GoogleCalendarConnection;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkItem;
use App\Services\GoogleCalendarClient;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

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

    public function test_upsert_creates_an_event_and_stores_the_event_id(): void
    {
        $this->connectAssignee();
        Http::fake(['www.googleapis.com/calendar/v3/*' => Http::response(['id' => 'evt_new'])]);

        (new SyncWorkItemCalendarEventJob(
            tenantId: $this->tenant->id, action: 'upsert', workItemId: $this->item->id,
        ))->handle(app(CurrentTenant::class), app(GoogleCalendarClient::class));

        $this->assertSame('evt_new', $this->item->fresh()->google_event_id);
    }

    public function test_upsert_is_a_no_op_when_the_assignee_has_no_connection(): void
    {
        Http::fake();

        (new SyncWorkItemCalendarEventJob(
            tenantId: $this->tenant->id, action: 'upsert', workItemId: $this->item->id,
        ))->handle(app(CurrentTenant::class), app(GoogleCalendarClient::class));

        Http::assertNothingSent();
        $this->assertNull($this->item->fresh()->google_event_id);
    }

    public function test_upsert_is_a_no_op_when_the_item_is_no_longer_syncable(): void
    {
        $this->connectAssignee();
        $this->item->update(['status' => 'done', 'done_at' => now()]);
        Http::fake();

        (new SyncWorkItemCalendarEventJob(
            tenantId: $this->tenant->id, action: 'upsert', workItemId: $this->item->id,
        ))->handle(app(CurrentTenant::class), app(GoogleCalendarClient::class));

        Http::assertNothingSent();
    }

    public function test_delete_removes_the_remote_event_and_clears_the_column(): void
    {
        $this->connectAssignee();
        $this->item->update(['google_event_id' => 'evt_old']);
        Http::fake(['www.googleapis.com/calendar/v3/*' => Http::response(null, 204)]);

        (new SyncWorkItemCalendarEventJob(
            tenantId: $this->tenant->id, action: 'delete',
            workItemId: $this->item->id, userId: $this->assigneeUser->id, googleEventId: 'evt_old',
        ))->handle(app(CurrentTenant::class), app(GoogleCalendarClient::class));

        Http::assertSent(fn ($request) => $request->method() === 'DELETE' && str_contains((string) $request->url(), 'evt_old'));
        $this->assertNull($this->item->fresh()->google_event_id);
    }

    public function test_delete_is_a_no_op_without_a_connection(): void
    {
        Http::fake();

        (new SyncWorkItemCalendarEventJob(
            tenantId: $this->tenant->id, action: 'delete',
            userId: $this->assigneeUser->id, googleEventId: 'evt_old',
        ))->handle(app(CurrentTenant::class), app(GoogleCalendarClient::class));

        Http::assertNothingSent();
    }

    public function test_handle_scopes_queries_to_the_dispatched_tenant_and_clears_context_after(): void
    {
        $this->connectAssignee();
        Http::fake(['www.googleapis.com/calendar/v3/*' => Http::response(['id' => 'evt_new'])]);
        $context = app(CurrentTenant::class);

        (new SyncWorkItemCalendarEventJob(
            tenantId: $this->tenant->id, action: 'upsert', workItemId: $this->item->id,
        ))->handle($context, app(GoogleCalendarClient::class));

        $this->assertFalse($context->check());
    }
}
