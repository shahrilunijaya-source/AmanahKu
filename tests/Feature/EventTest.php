<?php

namespace Tests\Feature;

use App\Models\CompanyEvent;
use App\Models\Employee;
use App\Models\EventRsvp;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserPermission;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Feature coverage for the company calendar & events module.
 * Harness (setUp / actingInTenant / hrActor) copied from CoreWritePathsTest.
 */
class EventTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Tenant $tenant;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create(['name' => 'Demo', 'email' => 'demo@example.com', 'password' => Hash::make('password')]);
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $this->user->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $this->employee = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->user->id,
            'name' => 'Demo', 'status' => 'active', 'workload' => 'green',
        ]);
    }

    private function actingInTenant(): self
    {
        $this->actingAs($this->user)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    private function hrActor(): User
    {
        $hr = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $hr->id,
            'name' => 'Boss', 'status' => 'active', 'workload' => 'green',
        ]);

        return $hr;
    }

    // ── Create ────────────────────────────────────────────────────

    public function test_privileged_user_creates_an_event(): void
    {
        // Arrange
        $hr = $this->hrActor();

        // Act
        $response = $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->post('/app/events', [
                'title' => 'Q3 Town Hall',
                'type' => 'townhall',
                'event_date' => now()->addDays(7)->toDateString(),
                'start_time' => '3:00 PM',
                'location' => 'PJ HQ',
                'description' => 'Quarterly update.',
            ]);

        // Assert
        $response->assertRedirect();
        $this->assertDatabaseHas('company_events', [
            'tenant_id' => $this->tenant->id,
            'title' => 'Q3 Town Hall',
            'type' => 'townhall',
            'location' => 'PJ HQ',
        ]);
    }

    public function test_plain_employee_cannot_create_an_event(): void
    {
        // Act
        $response = $this->actingInTenant()->post('/app/events', [
            'title' => 'Sneaky Event',
            'type' => 'social',
            'event_date' => now()->addDays(3)->toDateString(),
        ]);

        // Assert
        $response->assertForbidden();
        $this->assertDatabaseMissing('company_events', ['title' => 'Sneaky Event']);
    }

    public function test_employee_granted_event_create_can_create_an_event(): void
    {
        // Arrange: an intern posting on a senior manager's behalf, granted on the Roles screen
        UserPermission::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->user->id,
            'permission' => 'event.create', 'granted' => true,
        ]);

        // Act
        $screen = $this->actingInTenant()->get(route('app.screen', 'events'));
        $response = $this->actingInTenant()->post('/app/events', [
            'title' => 'Leadership Offsite',
            'type' => 'meeting',
            'event_date' => now()->addDays(5)->toDateString(),
        ]);

        // Assert
        $screen->assertOk()->assertSee('+ New event');
        $response->assertRedirect();
        $this->assertDatabaseHas('company_events', [
            'title' => 'Leadership Offsite',
            'created_by_employee_id' => $this->employee->id,
        ]);
    }

    public function test_event_create_is_an_overridable_permission_held_by_posting_roles(): void
    {
        $this->assertContains('event.create', Permissions::overridable());
        $this->assertTrue(Permissions::roleHas('manager', 'event.create'));
        $this->assertTrue(Permissions::roleHas('director', 'event.create'));
        $this->assertFalse(Permissions::roleHas('employee', 'event.create'));
    }

    // ── RSVP ──────────────────────────────────────────────────────

    public function test_employee_rsvps_to_an_event(): void
    {
        // Arrange
        $event = CompanyEvent::create([
            'tenant_id' => $this->tenant->id,
            'title' => 'Family Day', 'type' => 'social',
            'event_date' => now()->addDays(10)->toDateString(),
        ]);

        // Act
        $response = $this->actingInTenant()->post("/app/events/{$event->id}/rsvp", [
            'response' => 'going',
        ]);

        // Assert
        $response->assertRedirect();
        $this->assertDatabaseHas('event_rsvps', [
            'company_event_id' => $event->id,
            'employee_id' => $this->employee->id,
            'response' => 'going',
        ]);
    }

    public function test_changing_rsvp_updates_the_same_row_without_duplicating(): void
    {
        // Arrange — employee already RSVP'd "going".
        $event = CompanyEvent::create([
            'tenant_id' => $this->tenant->id,
            'title' => 'Town Hall', 'type' => 'townhall',
            'event_date' => now()->addDays(5)->toDateString(),
        ]);
        EventRsvp::create([
            'tenant_id' => $this->tenant->id,
            'company_event_id' => $event->id,
            'employee_id' => $this->employee->id,
            'response' => 'going',
        ]);

        // Act — same employee changes their mind.
        $response = $this->actingInTenant()->post("/app/events/{$event->id}/rsvp", [
            'response' => 'declined',
        ]);

        // Assert — exactly one row, updated in place (unique constraint respected).
        $response->assertRedirect();
        $this->assertSame(1, EventRsvp::where('company_event_id', $event->id)
            ->where('employee_id', $this->employee->id)->count());
        $this->assertSame('declined', EventRsvp::where('company_event_id', $event->id)
            ->where('employee_id', $this->employee->id)->first()->response);
    }

    // ── Past events ───────────────────────────────────────────────

    public function test_a_plain_employee_sees_past_events(): void
    {
        CompanyEvent::create([
            'tenant_id' => $this->tenant->id,
            'title' => 'Last Week Standup', 'type' => 'meeting',
            'event_date' => now()->subDays(5)->toDateString(),
        ]);

        $this->actingInTenant()->get('/app/events')
            ->assertOk()
            ->assertSee('Last Week Standup');
    }

    public function test_an_event_older_than_30_days_lands_in_the_collapsed_bucket_while_a_recent_one_does_not(): void
    {
        CompanyEvent::create([
            'tenant_id' => $this->tenant->id,
            'title' => 'Ancient Kickoff', 'type' => 'meeting',
            'event_date' => now()->subDays(45)->toDateString(),
        ]);
        CompanyEvent::create([
            'tenant_id' => $this->tenant->id,
            'title' => 'Last Week Standup', 'type' => 'meeting',
            'event_date' => now()->subDays(5)->toDateString(),
        ]);

        $response = $this->actingInTenant()->get('/app/events')->assertOk();

        // The recent one is rendered inline in the normal past-events rows.
        $response->assertSee('Last Week Standup');
        // The older one is still in the response (the collapsed bucket), just behind
        // the toggle rather than the visible recent rows — both are present in markup,
        // Alpine's x-show only hides it client-side.
        $response->assertSee('Ancient Kickoff');
        $response->assertSee('Older events (1)');
    }
}
