<?php

namespace Tests\Feature;

use App\Jobs\SyncWorkItemCalendarEventJob;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class WorkItemObserverTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Employee $employee;

    private Employee $otherEmployee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);

        $user = User::create(['name' => 'Demo', 'email' => 'demo@example.com', 'password' => Hash::make('password')]);
        $user->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $this->employee = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'name' => 'Demo', 'status' => 'active', 'workload' => 'green',
        ]);

        $other = User::create(['name' => 'Other', 'email' => 'other@example.com', 'password' => Hash::make('password')]);
        $other->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $this->otherEmployee = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $other->id,
            'name' => 'Other', 'status' => 'active', 'workload' => 'green',
        ]);
    }

    private function card(array $attrs = []): WorkItem
    {
        return $this->employee->workItems()->create(array_merge([
            'tenant_id' => $this->tenant->id, 'title' => 'X', 'type' => 'task',
            'priority' => 'low', 'status' => 'todo', 'progress' => 0,
        ], $attrs));
    }

    public function test_creating_a_card_with_a_due_date_and_assignee_dispatches_an_upsert(): void
    {
        Bus::fake();

        $card = $this->card(['due_at' => '2026-09-30']);

        Bus::assertDispatched(SyncWorkItemCalendarEventJob::class, fn ($job) => $job->action === 'upsert'
            && $job->workItemId === $card->id && $job->tenantId === $this->tenant->id);
    }

    public function test_creating_a_card_without_a_due_date_dispatches_nothing(): void
    {
        Bus::fake();

        $this->card();

        Bus::assertNotDispatched(SyncWorkItemCalendarEventJob::class);
    }

    public function test_changing_due_date_dispatches_an_upsert(): void
    {
        $card = $this->card();
        Bus::fake();

        $card->update(['due_at' => '2026-10-15']);

        Bus::assertDispatched(SyncWorkItemCalendarEventJob::class, fn ($job) => $job->action === 'upsert');
    }

    public function test_changing_unrelated_fields_dispatches_nothing(): void
    {
        $card = $this->card(['due_at' => '2026-09-30']);
        Bus::fake();

        $card->update(['priority' => 'high']);

        Bus::assertNotDispatched(SyncWorkItemCalendarEventJob::class);
    }

    public function test_clearing_due_date_dispatches_a_delete_for_the_existing_event(): void
    {
        $card = $this->card(['due_at' => '2026-09-30', 'google_event_id' => 'evt_1']);
        Bus::fake();

        $card->update(['due_at' => null]);

        Bus::assertDispatched(SyncWorkItemCalendarEventJob::class, fn ($job) => $job->action === 'delete'
            && $job->googleEventId === 'evt_1' && $job->userId === $this->employee->user_id);
    }

    public function test_marking_done_dispatches_a_delete(): void
    {
        $card = $this->card(['due_at' => '2026-09-30', 'google_event_id' => 'evt_1']);
        Bus::fake();

        $card->update(['status' => 'done', 'done_at' => now()]);

        Bus::assertDispatched(SyncWorkItemCalendarEventJob::class, fn ($job) => $job->action === 'delete');
    }

    public function test_archiving_dispatches_a_delete(): void
    {
        $card = $this->card(['due_at' => '2026-09-30', 'google_event_id' => 'evt_1', 'status' => 'done', 'done_at' => now()]);
        Bus::fake();

        $card->update(['archived_at' => now()]);

        Bus::assertDispatched(SyncWorkItemCalendarEventJob::class, fn ($job) => $job->action === 'delete');
    }

    public function test_reassigning_deletes_from_the_old_assignees_calendar_and_upserts_for_the_new_one(): void
    {
        $card = $this->card(['due_at' => '2026-09-30', 'google_event_id' => 'evt_1']);
        Bus::fake();

        $card->update(['employee_id' => $this->otherEmployee->id]);

        Bus::assertDispatched(SyncWorkItemCalendarEventJob::class, fn ($job) => $job->action === 'delete'
            && $job->googleEventId === 'evt_1' && $job->userId === $this->employee->user_id);
        Bus::assertDispatched(SyncWorkItemCalendarEventJob::class, fn ($job) => $job->action === 'upsert'
            && $job->workItemId === $card->id);
    }

    public function test_deleting_a_card_with_a_synced_event_dispatches_a_delete(): void
    {
        $card = $this->card(['due_at' => '2026-09-30', 'google_event_id' => 'evt_1']);
        Bus::fake();

        $card->delete();

        Bus::assertDispatched(SyncWorkItemCalendarEventJob::class, fn ($job) => $job->action === 'delete'
            && $job->googleEventId === 'evt_1' && $job->userId === $this->employee->user_id);
    }

    public function test_deleting_a_card_with_no_synced_event_dispatches_nothing(): void
    {
        $card = $this->card();
        Bus::fake();

        $card->delete();

        Bus::assertNotDispatched(SyncWorkItemCalendarEventJob::class);
    }
}
