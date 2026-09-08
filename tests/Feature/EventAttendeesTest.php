<?php

namespace Tests\Feature;

use App\Models\CompanyEvent;
use App\Models\Employee;
use App\Models\EventRsvp;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Governance, idempotency, cross-tenant isolation and card lifecycle for CR-11's
 * events.attendees endpoint. tests/Acceptance/CR11Test.php already covers the full
 * happy path in detail; this file targets the edges that leaves loose.
 */
class EventAttendeesTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
    }

    private function person(string $name, string $role = 'employee', ?Tenant $tenant = null): Employee
    {
        $tenant ??= $this->tenant;
        $user = User::create(['name' => $name, 'email' => strtolower($name).'@example.com', 'password' => Hash::make('password')]);
        $user->tenants()->attach($tenant->id, ['role' => $role]);

        return Employee::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'name' => $name, 'status' => 'active', 'workload' => 'green']);
    }

    private function actingInTenantAs(Employee $employee): static
    {
        $this->actingAs($employee->user)->withSession(['current_tenant' => $employee->tenant_id]);

        return $this;
    }

    private function event(Tenant $tenant): CompanyEvent
    {
        return CompanyEvent::create([
            'tenant_id' => $tenant->id,
            'title' => 'Quarterly Townhall',
            'type' => 'townhall',
            'event_date' => '2026-10-01',
            'starts_at' => '2026-10-01 10:00',
            'ends_at' => '2026-10-01 12:00',
            'location' => 'HQ Auditorium',
        ]);
    }

    #[Test]
    public function a_plain_employee_cannot_set_attendees_but_a_manager_can(): void
    {
        $manager = $this->person('Manager', 'manager');
        $staff = $this->person('Staff');
        $event = $this->event($this->tenant);

        $this->actingInTenantAs($staff)
            ->postJson("/app/events/{$event->id}/attendees", ['attendees' => [$staff->id]])
            ->assertStatus(403);

        $this->actingInTenantAs($manager)
            ->postJson("/app/events/{$event->id}/attendees", ['attendees' => [$staff->id]])
            ->assertSuccessful();

        $this->assertSame(1, EventRsvp::where('company_event_id', $event->id)->count());
    }

    #[Test]
    public function an_event_from_another_tenant_404s(): void
    {
        $otherTenant = Tenant::create(['slug' => 'other', 'name' => 'Other', 'initials' => 'OT']);
        $hr = $this->person('HR', 'hr');
        $foreignEvent = $this->event($otherTenant);

        $this->actingInTenantAs($hr)
            ->postJson("/app/events/{$foreignEvent->id}/attendees", ['attendees' => [$hr->id]])
            ->assertStatus(403);
    }

    #[Test]
    public function removing_and_re_adding_the_same_attendee_makes_a_second_card_not_a_duplicate_active_one(): void
    {
        $hr = $this->person('HR', 'hr');
        $staff = $this->person('Staff');
        $event = $this->event($this->tenant);

        $this->actingInTenantAs($hr)->postJson("/app/events/{$event->id}/attendees", ['attendees' => [$staff->id]])->assertSuccessful();
        $firstCard = WorkItem::where('company_event_id', $event->id)->where('employee_id', $staff->id)->firstOrFail();

        $this->actingInTenantAs($hr)->postJson("/app/events/{$event->id}/attendees", ['attendees' => []])->assertSuccessful();
        $this->assertNotNull($firstCard->fresh()->archived_at);
        $this->assertNotNull($firstCard->fresh()->cancelled_at);

        $this->actingInTenantAs($hr)->postJson("/app/events/{$event->id}/attendees", ['attendees' => [$staff->id]])->assertSuccessful();

        $this->assertSame(1, WorkItem::where('company_event_id', $event->id)->whereNull('archived_at')->count());
        $this->assertSame(2, WorkItem::where('company_event_id', $event->id)->count(), 'the old archived card stays, a fresh one is made');

        $deletes = DB::table('port_outbox')->where('port', 'calendar')->where('method', 'deleteEvent')->count();
        $upserts = DB::table('port_outbox')->where('port', 'calendar')->where('method', 'upsertEvent')->count();
        $this->assertSame(1, $deletes);
        $this->assertSame(2, $upserts);
    }

    #[Test]
    public function attendees_must_belong_to_the_same_tenant(): void
    {
        $hr = $this->person('HR', 'hr');
        $otherTenant = Tenant::create(['slug' => 'other', 'name' => 'Other', 'initials' => 'OT']);
        $stranger = $this->person('Stranger', 'employee', $otherTenant);
        $event = $this->event($this->tenant);

        $this->actingInTenantAs($hr)
            ->postJson("/app/events/{$event->id}/attendees", ['attendees' => [$stranger->id]])
            ->assertStatus(422);
    }

    /** QA F3 (CR-11 scope 5): the creator may set attendees even without a privileged role. */
    #[Test]
    public function the_event_creator_may_set_attendees_without_a_privileged_role(): void
    {
        $creator = $this->person('Creator');
        $guest = $this->person('Guest');
        $event = $this->event($this->tenant);
        $event->update(['created_by_employee_id' => $creator->id]);

        $this->actingInTenantAs($creator)
            ->postJson("/app/events/{$event->id}/attendees", ['attendees' => [$guest->id]])
            ->assertSuccessful();

        $this->assertDatabaseHas('event_rsvps', ['company_event_id' => $event->id, 'employee_id' => $guest->id]);

        $this->actingInTenantAs($creator)->get("/app/events/{$event->id}")
            ->assertOk()
            ->assertSee('data-js="event-attendees-form"', false);
    }

    /** QA F1/F2 (CR-11): the Events screen links to the event page and its form takes the exact slot. */
    #[Test]
    public function the_events_screen_links_to_the_event_page_and_asks_for_start_and_end_times(): void
    {
        $hr = $this->person('Hr', 'hr');
        $event = $this->event($this->tenant);

        $this->actingInTenantAs($hr)->get('/app/events')
            ->assertOk()
            ->assertSee(route('events.show', $event), false)
            ->assertSee('name="starts_at"', false)
            ->assertSee('name="ends_at"', false);
    }

    /** QA F4/F5 (CR-11): the plain form redirects back instead of showing JSON, and the organiser can mark attendance from the page. */
    #[Test]
    public function the_attendees_form_redirects_back_and_offers_a_status_control_per_attendee(): void
    {
        $hr = $this->person('Hr', 'hr');
        $guest = $this->person('Guest');
        $event = $this->event($this->tenant);

        $this->actingInTenantAs($hr)
            ->from("/app/events/{$event->id}")
            ->post("/app/events/{$event->id}/attendees", ['attendees' => ['', (string) $guest->id]])
            ->assertRedirect("/app/events/{$event->id}");

        $this->actingInTenantAs($hr)->get("/app/events/{$event->id}")
            ->assertOk()
            ->assertSee('data-js="event-attendee-status"', false)
            ->assertSee('name="employee_id" value="'.$guest->id.'"', false);

        $this->actingInTenantAs($hr)
            ->post("/app/events/{$event->id}/attendees", ['attendees' => ['']])
            ->assertRedirect();
        $this->assertDatabaseMissing('event_rsvps', ['company_event_id' => $event->id, 'employee_id' => $guest->id]);
    }
}
