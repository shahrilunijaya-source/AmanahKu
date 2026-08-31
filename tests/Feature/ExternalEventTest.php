<?php

namespace Tests\Feature;

use App\Models\CompanyEvent;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Coverage for external events (training/workshops hosted outside the company) on the
 * Events screen — the feature moved here from the TOT screen's old External tab. An
 * event is external the moment it carries a host (CompanyEvent::isExternal()); the
 * fields, the @mention summons, the poster-only edit and the wider posting roster are
 * all carried over unchanged from the old model/controller pairing that used to live
 * on the TOT screen's External tab.
 */
class ExternalEventTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private function seedWorkspace(string $role): User
    {
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);

        $user = User::create([
            'name' => 'Actor', 'email' => $role.'@example.com', 'password' => Hash::make('password'),
        ]);
        $user->tenants()->attach($this->tenant->id, ['role' => $role]);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'name' => 'Actor', 'status' => 'active', 'workload' => 'green',
        ]);

        return $user;
    }

    private function actingInTenant(User $user): self
    {
        $this->actingAs($user)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Cybersecurity in the Age of NeoCloud',
            'type' => 'training',
            'host' => 'Techdata Systems',
            'description' => 'How AI, Cloud, and Cybersecurity come together.',
            // A future date, not a fixed one: this payload also seeds view tests that
            // depend on the event landing in the Upcoming list rather than Past events.
            'event_date' => now()->addDays(14)->toDateString(),
            'start_time' => '10:00 AM – 12:00 PM',
            'location' => 'Techdata Systems, Level 3 Conference Room',
            'venue_map_url' => 'https://maps.app.goo.gl/pb47NuLjfLLRsP4t6',
            'registration_url' => 'https://forms.gle/M7NSkbmbnbr64pZC7',
        ], $overrides);
    }

    public function test_a_manager_can_post_an_external_event(): void
    {
        $user = $this->seedWorkspace('manager');

        $this->actingInTenant($user)
            ->post('/app/events', $this->payload())
            ->assertRedirect();

        $this->assertDatabaseHas('company_events', [
            'tenant_id' => $this->tenant->id,
            'title' => 'Cybersecurity in the Age of NeoCloud',
            'host' => 'Techdata Systems',
        ]);
    }

    public function test_an_employee_cannot_post_an_external_event(): void
    {
        $user = $this->seedWorkspace('employee');

        $this->actingInTenant($user)
            ->post('/app/events', $this->payload())
            ->assertForbidden();

        $this->assertSame(0, CompanyEvent::count());
    }

    public function test_posting_stamps_the_posters_employee_id(): void
    {
        $user = $this->seedWorkspace('hr');

        $this->actingInTenant($user)->post('/app/events', $this->payload());

        $employee = Employee::where('user_id', $user->id)->firstOrFail();
        $this->assertSame($employee->id, CompanyEvent::first()->created_by_employee_id);
    }

    public function test_a_privileged_role_can_remove_an_external_event(): void
    {
        $user = $this->seedWorkspace('management');
        $event = CompanyEvent::create(array_merge($this->payload(), ['tenant_id' => $this->tenant->id]));

        $this->actingInTenant($user)
            ->post("/app/events/{$event->id}/delete")
            ->assertRedirect();

        $this->assertSame(0, CompanyEvent::count());
    }

    public function test_an_employee_cannot_remove_an_external_event(): void
    {
        $user = $this->seedWorkspace('employee');
        $event = CompanyEvent::create(array_merge($this->payload(), ['tenant_id' => $this->tenant->id]));

        $this->actingInTenant($user)
            ->post("/app/events/{$event->id}/delete")
            ->assertForbidden();

        $this->assertSame(1, CompanyEvent::count());
    }

    public function test_a_foreign_tenants_event_403s_instead_of_reaching_a_privileged_actor(): void
    {
        $user = $this->seedWorkspace('management');

        $otherTenant = Tenant::create(['slug' => 'other', 'name' => 'Other', 'initials' => 'OT']);
        $foreignEvent = CompanyEvent::create(array_merge($this->payload(), ['tenant_id' => $otherTenant->id]));

        $this->actingInTenant($user)
            ->post("/app/events/{$foreignEvent->id}/delete")
            ->assertForbidden();

        // Not ::count(): by now the request has set CurrentTenant to "acme", so the
        // model's own tenant global scope would silently hide the other tenant's row.
        // assertDatabaseHas reads the table directly, unscoped.
        $this->assertDatabaseHas('company_events', ['id' => $foreignEvent->id]);
    }

    public function test_the_poster_can_update_their_own_external_event(): void
    {
        $user = $this->seedWorkspace('manager');
        $poster = Employee::where('user_id', $user->id)->firstOrFail();
        $event = CompanyEvent::create(array_merge($this->payload(), [
            'tenant_id' => $this->tenant->id,
            'created_by_employee_id' => $poster->id,
        ]));

        $this->actingInTenant($user)
            ->post("/app/events/{$event->id}", $this->payload(['title' => 'Updated Title']))
            ->assertRedirect();

        $this->assertDatabaseHas('company_events', [
            'id' => $event->id,
            'title' => 'Updated Title',
        ]);
    }

    public function test_a_privileged_role_who_did_not_post_it_cannot_update_an_external_event(): void
    {
        $poster = $this->seedWorkspace('manager');
        $posterEmployee = Employee::where('user_id', $poster->id)->firstOrFail();
        $event = CompanyEvent::create(array_merge($this->payload(), [
            'tenant_id' => $this->tenant->id,
            'created_by_employee_id' => $posterEmployee->id,
        ]));

        $otherHr = User::create([
            'name' => 'Other HR', 'email' => 'otherhr@example.com', 'password' => Hash::make('password'),
        ]);
        $otherHr->tenants()->attach($this->tenant->id, ['role' => 'hr']);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $otherHr->id,
            'name' => 'Other HR', 'status' => 'active', 'workload' => 'green',
        ]);

        $this->actingInTenant($otherHr)
            ->post("/app/events/{$event->id}", $this->payload(['title' => 'Hijacked Title']))
            ->assertForbidden();

        $this->assertDatabaseHas('company_events', [
            'id' => $event->id,
            'title' => 'Cybersecurity in the Age of NeoCloud',
        ]);
    }

    public function test_updating_a_foreign_tenants_event_403s(): void
    {
        $user = $this->seedWorkspace('manager');
        $poster = Employee::where('user_id', $user->id)->firstOrFail();

        $otherTenant = Tenant::create(['slug' => 'other', 'name' => 'Other', 'initials' => 'OT']);
        $foreignEvent = CompanyEvent::create(array_merge($this->payload(), [
            'tenant_id' => $otherTenant->id,
            'created_by_employee_id' => $poster->id,
        ]));

        $this->actingInTenant($user)
            ->post("/app/events/{$foreignEvent->id}", $this->payload(['title' => 'Nope']))
            ->assertForbidden();
    }

    public function test_updating_notifies_a_newly_tagged_person_but_not_one_already_tagged(): void
    {
        $poster = $this->seedWorkspace('manager');
        $posterEmployee = Employee::where('user_id', $poster->id)->firstOrFail();
        $already = $this->colleague('Aminah');
        $new = $this->colleague('Kamal');

        $event = CompanyEvent::create(array_merge($this->payload([
            'description' => 'Ops team, @Aminah is expected there.',
        ]), [
            'tenant_id' => $this->tenant->id,
            'created_by_employee_id' => $posterEmployee->id,
            'tagged_employee_ids' => [$already->id],
        ]));

        $this->actingInTenant($poster)->post("/app/events/{$event->id}", $this->payload([
            'description' => 'Ops team, @Aminah and @Kamal are expected there.',
            'tagged' => [$already->id, $new->id],
        ]))->assertRedirect();

        $this->assertEqualsCanonicalizing([$already->id, $new->id], $event->fresh()->taggedIds());
        $this->assertDatabaseHas('app_notifications', ['user_id' => $new->user_id]);
        $this->assertDatabaseMissing('app_notifications', ['user_id' => $already->user_id]);
    }

    public function test_the_board_shows_a_posted_external_event_to_a_plain_employee(): void
    {
        $user = $this->seedWorkspace('employee');
        CompanyEvent::create(array_merge($this->payload(), ['tenant_id' => $this->tenant->id]));

        $this->actingInTenant($user)->get('/app/events')
            ->assertOk()
            ->assertSee('Cybersecurity in the Age of NeoCloud');
    }

    public function test_a_plain_employee_sees_no_post_form(): void
    {
        $user = $this->seedWorkspace('employee');

        $this->actingInTenant($user)->get('/app/events')
            ->assertOk()
            ->assertDontSee('name="registration_url"', false);
    }

    public function test_a_manager_sees_the_post_form(): void
    {
        $user = $this->seedWorkspace('manager');

        $this->actingInTenant($user)->get('/app/events')
            ->assertOk()
            ->assertSee('name="registration_url"', false);
    }

    /** A colleague who can be @mentioned: their own user, so the bell has somewhere to land. */
    private function colleague(string $name, ?Tenant $tenant = null): Employee
    {
        $tenant ??= $this->tenant;
        $user = User::create([
            'name' => $name, 'email' => str($name)->slug().'@example.com', 'password' => Hash::make('password'),
        ]);
        $user->tenants()->attach($tenant->id, ['role' => 'employee']);

        return Employee::create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id,
            'name' => $name, 'status' => 'active', 'workload' => 'green',
        ]);
    }

    public function test_tagging_someone_notifies_them_that_they_must_register(): void
    {
        $poster = $this->seedWorkspace('manager');
        $tagged = $this->colleague('Aminah');

        $this->actingInTenant($poster)->post('/app/events', $this->payload([
            'description' => 'Everyone in ops, especially @Aminah, must attend.',
            'tagged' => [$tagged->id],
        ]))->assertRedirect();

        $this->assertSame([$tagged->id], CompanyEvent::first()->taggedIds());
        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $tagged->user_id,
            'title' => "You're required to attend: Cybersecurity in the Age of NeoCloud",
        ]);
    }

    public function test_a_mention_deleted_from_the_description_tags_nobody(): void
    {
        $poster = $this->seedWorkspace('manager');
        $tagged = $this->colleague('Aminah');

        $this->actingInTenant($poster)->post('/app/events', $this->payload([
            'description' => 'Changed my mind, nobody in particular has to come.',
            'tagged' => [$tagged->id],
        ]))->assertRedirect();

        $this->assertSame([], CompanyEvent::first()->taggedIds());
        $this->assertDatabaseCount('app_notifications', 0);
    }

    public function test_an_employee_from_another_tenant_cannot_be_tagged(): void
    {
        $poster = $this->seedWorkspace('manager');
        $other = Tenant::create(['slug' => 'other', 'name' => 'Other', 'initials' => 'OT']);
        $outsider = $this->colleague('Zarina', $other);

        $this->actingInTenant($poster)->post('/app/events', $this->payload([
            'description' => 'Come along @Zarina.',
            'tagged' => [$outsider->id],
        ]))->assertRedirect();

        $this->assertSame([], CompanyEvent::first()->taggedIds());
        $this->assertDatabaseCount('app_notifications', 0);
    }

    public function test_the_poster_sees_an_edit_button_for_their_own_event(): void
    {
        $user = $this->seedWorkspace('manager');
        $poster = Employee::where('user_id', $user->id)->firstOrFail();
        CompanyEvent::create(array_merge($this->payload(), [
            'tenant_id' => $this->tenant->id,
            'created_by_employee_id' => $poster->id,
        ]));

        $this->actingInTenant($user)->get('/app/events')
            ->assertOk()
            ->assertSee('editEvent = JSON.parse(', false);
    }

    public function test_a_non_poster_does_not_see_an_edit_button_for_someone_elses_event(): void
    {
        $poster = $this->seedWorkspace('manager');
        $posterEmployee = Employee::where('user_id', $poster->id)->firstOrFail();
        CompanyEvent::create(array_merge($this->payload(), [
            'tenant_id' => $this->tenant->id,
            'created_by_employee_id' => $posterEmployee->id,
        ]));

        $otherManager = User::create([
            'name' => 'Other Manager', 'email' => 'othermanager@example.com', 'password' => Hash::make('password'),
        ]);
        $otherManager->tenants()->attach($this->tenant->id, ['role' => 'manager']);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $otherManager->id,
            'name' => 'Other Manager', 'status' => 'active', 'workload' => 'green',
        ]);

        $this->actingInTenant($otherManager)->get('/app/events')
            ->assertOk()
            ->assertDontSee('editEvent = JSON.parse(', false);
    }

    public function test_a_tagged_viewer_is_told_on_the_board_that_they_must_register(): void
    {
        $poster = $this->seedWorkspace('manager');
        $tagged = $this->colleague('Aminah');
        $taggedUser = $tagged->user;

        $this->actingInTenant($poster)->post('/app/events', $this->payload([
            'description' => 'Ops team, @Aminah is expected there.',
            'tagged' => [$tagged->id],
        ]));

        $this->actingAs($taggedUser)->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/events')->assertOk()
            ->assertSee('You were tagged');

        $this->actingInTenant($poster)->get('/app/events')
            ->assertOk()
            ->assertDontSee('You were tagged');
    }

    public function test_an_external_event_shows_register_link_and_no_rsvp(): void
    {
        $user = $this->seedWorkspace('employee');
        CompanyEvent::create(array_merge($this->payload(), [
            'tenant_id' => $this->tenant->id,
            'event_date' => now()->addDays(3)->toDateString(),
        ]));

        $this->actingInTenant($user)->get('/app/events')
            ->assertOk()
            ->assertSee('Register')
            ->assertDontSee('name="response" value="going"', false);
    }

    public function test_an_internal_event_shows_rsvp_and_no_register_link(): void
    {
        $user = $this->seedWorkspace('employee');
        CompanyEvent::create([
            'tenant_id' => $this->tenant->id,
            'title' => 'Q3 Town Hall',
            'type' => 'townhall',
            'event_date' => now()->addDays(3)->toDateString(),
        ]);

        $this->actingInTenant($user)->get('/app/events')
            ->assertOk()
            ->assertSee('name="response" value="going"', false)
            ->assertDontSee('Register');
    }
}
