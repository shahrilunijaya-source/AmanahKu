<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Governance edges CR17Test does not pin: cross-tenant isolation on the
 * {card}-bound routes and on the digest, a deeper (3-level) reporting chain on the
 * scoped exceptions page, the incident-window boundary being inclusive, and the
 * reassign/incident validation paths in isolation.
 */
class ManagementExceptionsTest extends TestCase
{
    use RefreshDatabase;

    private const TODAY = '2026-09-08';

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        Carbon::setTestNow(self::TODAY.' 14:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function person(Tenant $tenant, string $name, string $role = 'employee', array $attrs = []): Employee
    {
        $user = User::create(['name' => $name, 'email' => strtolower(preg_replace('/\W+/', '', $name)).uniqid().'@example.com', 'password' => Hash::make('password')]);
        $user->tenants()->attach($tenant->id, ['role' => $role]);

        return Employee::create(array_merge(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'name' => $name, 'status' => 'active', 'workload' => 'green'], $attrs));
    }

    private function actingIn(Tenant $tenant, Employee $employee): static
    {
        $this->actingAs($employee->user)->withSession(['current_tenant' => $tenant->id]);

        return $this;
    }

    private function card(Tenant $tenant, Employee $owner, string $title, int $daysAgo): WorkItem
    {
        return $owner->workItems()->create([
            'tenant_id' => $tenant->id, 'title' => $title, 'type' => 'task', 'priority' => 'medium',
            'status' => 'in_progress', 'progress' => 0, 'due_at' => Carbon::parse(self::TODAY)->subDays($daysAgo)->toDateString(),
        ]);
    }

    #[Test]
    public function a_card_from_another_tenant_404s_on_nudge_and_reassign(): void
    {
        $director = $this->person($this->tenant, 'Director', 'director');
        $tenantB = Tenant::create(['slug' => 'other', 'name' => 'Other Co', 'initials' => 'OC']);
        $ownerB = $this->person($tenantB, 'Owner B');
        $cardB = $this->card($tenantB, $ownerB, 'Belongs to tenant B', 5);

        $this->actingIn($this->tenant, $director)
            ->postJson("/app/management/overdue/{$cardB->id}/nudge")->assertStatus(404);

        $this->actingIn($this->tenant, $director)
            ->postJson("/app/management/overdue/{$cardB->id}/reassign", ['employee_id' => $director->id, 'reason' => 'x'])
            ->assertStatus(404);
    }

    #[Test]
    public function reassign_rejects_an_employee_id_from_another_tenant(): void
    {
        $manager = $this->person($this->tenant, 'Manager', 'manager');
        $owner = $this->person($this->tenant, 'Owner', 'employee', ['reports_to_id' => $manager->id]);
        $card = $this->card($this->tenant, $owner, 'Local card', 3);

        $tenantB = Tenant::create(['slug' => 'other', 'name' => 'Other Co', 'initials' => 'OC']);
        $foreignEmployee = $this->person($tenantB, 'Foreign Employee');

        $this->actingIn($this->tenant, $manager)
            ->postJson("/app/management/overdue/{$card->id}/reassign", ['employee_id' => $foreignEmployee->id, 'reason' => 'x'])
            ->assertStatus(422)->assertJsonValidationErrors(['employee_id']);
    }

    #[Test]
    public function reassign_to_the_current_owner_is_rejected(): void
    {
        $director = $this->person($this->tenant, 'Director', 'director');
        $owner = $this->person($this->tenant, 'Owner');
        $card = $this->card($this->tenant, $owner, 'Card', 3);

        $this->actingIn($this->tenant, $director)
            ->postJson("/app/management/overdue/{$card->id}/reassign", ['employee_id' => $owner->id, 'reason' => 'x'])
            ->assertStatus(422)->assertJsonValidationErrors(['employee_id']);
    }

    #[Test]
    public function the_incident_window_is_inclusive_at_both_ends(): void
    {
        $director = $this->person($this->tenant, 'Director', 'director');
        $hr = $this->person($this->tenant, 'HR', 'hr');
        $atStart = $this->person($this->tenant, 'At Start');
        $atEnd = $this->person($this->tenant, 'At End');
        $justAfter = $this->person($this->tenant, 'Just After');

        AttendanceRecord::create(['tenant_id' => $this->tenant->id, 'employee_id' => $atStart->id, 'date' => self::TODAY, 'clock_in' => '09:00:00', 'expected_start' => '09:00:00', 'type' => 'standard']);
        AttendanceRecord::create(['tenant_id' => $this->tenant->id, 'employee_id' => $atEnd->id, 'date' => self::TODAY, 'clock_in' => '10:00:00', 'expected_start' => '09:00:00', 'type' => 'standard']);
        AttendanceRecord::create(['tenant_id' => $this->tenant->id, 'employee_id' => $justAfter->id, 'date' => self::TODAY, 'clock_in' => '10:00:01', 'expected_start' => '09:00:00', 'type' => 'standard']);

        $this->actingIn($this->tenant, $hr)
            ->postJson('/app/attendance/incidents', ['starts_at' => self::TODAY.' 09:00:00', 'ends_at' => self::TODAY.' 10:00:00', 'note' => 'Outage'])
            ->assertSuccessful();

        $html = $this->actingIn($this->tenant, $director)->get('/app/dash')->assertOk()->getContent();

        foreach ([$atStart, $atEnd] as $person) {
            $start = strpos($html, 'data-late-row="'.$person->id.'"');
            $this->assertNotFalse($start);
            $end = strpos($html, 'data-late-row=', $start + 10);
            $this->assertStringContainsString('Unverified', substr($html, $start, $end === false ? 600 : $end - $start));
        }

        $start = strpos($html, 'data-late-row="'.$justAfter->id.'"');
        $end = strpos($html, 'data-late-row=', $start + 10);
        $this->assertStringNotContainsString('Unverified', substr($html, $start, $end === false ? 600 : $end - $start));
    }

    #[Test]
    public function storing_an_incident_validates_required_fields(): void
    {
        $hr = $this->person($this->tenant, 'HR', 'hr');

        $this->actingIn($this->tenant, $hr)->postJson('/app/attendance/incidents', [])
            ->assertStatus(422)->assertJsonValidationErrors(['starts_at', 'ends_at', 'note']);
    }

    #[Test]
    public function hr_marks_an_incident_window_from_the_attendance_setup_form(): void
    {
        $hr = $this->person($this->tenant, 'HR', 'hr');
        $manager = $this->person($this->tenant, 'Setup manager', 'management');

        $this->actingIn($this->tenant, $hr)->get('/app/attendance-admin')->assertOk()
            ->assertSee('data-incident-form', false)->assertSee('Mark incident window');
        $this->actingIn($this->tenant, $manager)->get('/app/attendance-admin')->assertOk()
            ->assertDontSee('data-incident-form', false);

        $this->actingIn($this->tenant, $hr)->from('/app/attendance-admin')
            ->post('/app/attendance/incidents', ['starts_at' => self::TODAY.' 09:30', 'ends_at' => self::TODAY.' 10:00', 'note' => 'Clock server down'])
            ->assertRedirect('/app/attendance-admin')->assertSessionHas('ok');
        $this->assertDatabaseHas('attendance_incidents', ['tenant_id' => $this->tenant->id, 'note' => 'Clock server down']);

        $this->actingIn($this->tenant, $hr)->from('/app/attendance-admin')
            ->post('/app/attendance/incidents', ['starts_at' => self::TODAY.' 10:00', 'ends_at' => self::TODAY.' 09:30', 'note' => 'Reversed'])
            ->assertRedirect('/app/attendance-admin')->assertSessionHasErrors('ends_at');
    }

    #[Test]
    public function a_branch_managers_scoped_page_reaches_three_levels_down_the_chain(): void
    {
        $senior = $this->person($this->tenant, 'Senior Manager', 'manager');
        $senior->user->tenants()->updateExistingPivot($this->tenant->id, ['data_scope' => 'branch']);
        $mid = $this->person($this->tenant, 'Mid Manager', 'manager', ['reports_to_id' => $senior->id]);
        $lead = $this->person($this->tenant, 'Lead', 'employee', ['reports_to_id' => $mid->id]);
        $staffer = $this->person($this->tenant, 'Staffer', 'employee', ['reports_to_id' => $lead->id]);
        $card = $this->card($this->tenant, $staffer, 'Three levels down', 4);

        $this->actingIn($this->tenant, $senior)->get('/app/management/exceptions')->assertOk()
            ->assertSee('data-card="'.$card->id.'"', false)
            ->assertSee('data-overdue-owner="'.$staffer->id.'"', false);
    }

    #[Test]
    public function the_reassign_button_only_renders_for_a_viewer_who_may_reassign(): void
    {
        $hr = $this->person($this->tenant, 'HR', 'hr');
        $director = $this->person($this->tenant, 'Director', 'director');
        $senior = $this->person($this->tenant, 'Senior Manager', 'manager');
        $senior->user->tenants()->updateExistingPivot($this->tenant->id, ['data_scope' => 'branch']);
        $mid = $this->person($this->tenant, 'Mid Manager', 'manager', ['reports_to_id' => $senior->id]);
        $staffer = $this->person($this->tenant, 'Staffer', 'employee', ['reports_to_id' => $mid->id]);
        $direct = $this->card($this->tenant, $mid, 'Mid own card', 3);
        $indirect = $this->card($this->tenant, $staffer, 'Staffer card', 3);

        $reassign = fn (WorkItem $c) => 'data-reassign-url="'.url('/app/management/overdue/'.$c->id.'/reassign').'"';

        $this->actingIn($this->tenant, $hr)->get('/app/dash')->assertOk()
            ->assertSee('data-nudge-url', false)->assertDontSee('data-reassign-url', false);
        $this->actingIn($this->tenant, $director)->get('/app/dash')->assertOk()
            ->assertSee($reassign($direct), false)->assertSee($reassign($indirect), false);
        $this->actingIn($this->tenant, $senior)->get('/app/management/exceptions')->assertOk()
            ->assertSee($reassign($direct), false)->assertDontSee($reassign($indirect), false);
    }

    #[Test]
    public function the_digest_never_mixes_up_two_tenants(): void
    {
        $tenantB = Tenant::create(['slug' => 'other', 'name' => 'Other Co', 'initials' => 'OC']);
        $directorA = $this->person($this->tenant, 'Director A', 'director');
        $directorB = $this->person($tenantB, 'Director B', 'director');

        $this->artisan('management:digest')->run();

        $rowsA = DB::table('port_outbox')->where('tenant_id', $this->tenant->id)->where('port', 'mail')->get();
        $rowsB = DB::table('port_outbox')->where('tenant_id', $tenantB->id)->where('port', 'mail')->get();

        $this->assertGreaterThanOrEqual(1, $rowsA->count());
        $this->assertGreaterThanOrEqual(1, $rowsB->count());

        $toA = json_decode($rowsA->first()->payload, true)['to'];
        $toB = json_decode($rowsB->first()->payload, true)['to'];

        $this->assertContains($directorA->user->email, $toA);
        $this->assertNotContains($directorB->user->email, $toA);
        $this->assertContains($directorB->user->email, $toB);
        $this->assertNotContains($directorA->user->email, $toB);
    }
}
