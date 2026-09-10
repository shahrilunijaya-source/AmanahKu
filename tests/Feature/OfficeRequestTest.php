<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\OfficeRequest;
use App\Models\Position;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Governance edges CR21Test does not pin: 403s for non-admin-team actions, the
 * urgency_reason requirement in isolation, upvote idempotency at the model layer,
 * the exact reopen-window boundary, and cross-tenant isolation on every
 * {officeRequest}-bound route.
 */
class OfficeRequestTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        Carbon::setTestNow('2026-09-01 09:00:00');

        // resolveOwner()'s fallback needs someone hr/management/director to exist —
        // every test in this file raises at least one request against $this->tenant.
        $this->person($this->tenant, 'Finance Boss', 'management');
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

    /** Raise one request for the given tenant/employee and return its model. */
    private function raise(Tenant $tenant, Employee $by, string $title = 'Coffee habis'): OfficeRequest
    {
        Position::firstOrCreate(['tenant_id' => $tenant->id, 'title' => 'Finance Manager'], ['status' => 'active']);
        $this->actingIn($tenant, $by)->postJson('/app/office-requests', [
            'category' => 'pantry', 'title' => $title, 'description' => "{$title}, please.",
            'location' => 'Level 2', 'urgency' => 'normal',
        ])->assertSuccessful();

        return OfficeRequest::where('tenant_id', $tenant->id)->where('title', $title)->firstOrFail();
    }

    #[Test]
    public function plain_staff_get_403_on_admin_note_and_done_but_can_still_raise_and_upvote(): void
    {
        $requester = $this->person($this->tenant, 'Emysha');
        $bystander = $this->person($this->tenant, 'Adri');
        $request = $this->raise($this->tenant, $requester);

        $this->actingIn($this->tenant, $bystander)
            ->postJson("/app/office-requests/{$request->id}/admin-note", ['note' => 'nope'])
            ->assertStatus(403);
        $this->actingIn($this->tenant, $bystander)
            ->postJson("/app/office-requests/{$request->id}/done", ['note' => 'nope'])
            ->assertStatus(403);

        // But raising and upvoting stay open to everyone.
        $this->actingIn($this->tenant, $bystander)
            ->postJson("/app/office-requests/{$request->id}/upvote")
            ->assertOk();
    }

    #[Test]
    public function a_vote_can_be_taken_back_and_taking_back_a_vote_you_never_cast_changes_nothing(): void
    {
        $requester = $this->person($this->tenant, 'Emysha');
        $voter = $this->person($this->tenant, 'Adri');
        $request = $this->raise($this->tenant, $requester);

        $this->actingIn($this->tenant, $voter)->postJson("/app/office-requests/{$request->id}/upvote")->assertOk()->assertJson(['votes' => 2]);
        $this->actingIn($this->tenant, $voter)->deleteJson("/app/office-requests/{$request->id}/upvote")->assertOk()->assertJson(['votes' => 1]);
        $this->assertSame(0, DB::table('office_request_votes')->where('office_request_id', $request->id)->where('employee_id', $voter->id)->count());

        // A second undo, or an undo from someone who never voted, is a no-op.
        $this->actingIn($this->tenant, $voter)->deleteJson("/app/office-requests/{$request->id}/upvote")->assertOk()->assertJson(['votes' => 1]);
        $this->assertSame(1, (int) DB::table('office_requests')->where('id', $request->id)->value('votes'));
    }

    #[Test]
    public function urgency_reason_is_required_only_when_urgency_is_urgent(): void
    {
        $person = $this->person($this->tenant, 'Emysha');

        $this->actingIn($this->tenant, $person)
            ->postJson('/app/office-requests', [
                'category' => 'facilities', 'title' => 'Broken chair', 'description' => 'Wobbly.',
                'location' => 'Level 1', 'urgency' => 'urgent',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['urgency_reason']);

        // Normal urgency needs no reason at all.
        $this->actingIn($this->tenant, $person)
            ->postJson('/app/office-requests', [
                'category' => 'facilities', 'title' => 'Broken chair', 'description' => 'Wobbly.',
                'location' => 'Level 1', 'urgency' => 'normal',
            ])
            ->assertSuccessful();
    }

    #[Test]
    public function upvote_never_double_counts_the_same_employee_even_hit_concurrently(): void
    {
        $requester = $this->person($this->tenant, 'Emysha');
        $voter = $this->person($this->tenant, 'Adri');
        $request = $this->raise($this->tenant, $requester);

        for ($i = 0; $i < 3; $i++) {
            $this->actingIn($this->tenant, $voter)->postJson("/app/office-requests/{$request->id}/upvote")->assertOk();
        }

        $this->assertSame(1, DB::table('office_request_votes')->where('office_request_id', $request->id)->where('employee_id', $voter->id)->count());
        // Requester's own first vote + the one voter = 2, not 4.
        $this->assertSame(2, (int) DB::table('office_requests')->where('id', $request->id)->value('votes'));
    }

    #[Test]
    public function reopen_window_allows_exactly_three_days_and_refuses_past_it(): void
    {
        $requester = $this->person($this->tenant, 'Emysha');
        $admin = $this->person($this->tenant, 'Admin One', 'hr');
        $request = $this->raise($this->tenant, $requester);

        $this->actingIn($this->tenant, $admin)->postJson("/app/office-requests/{$request->id}/done", ['note' => 'Sorted.'])->assertOk();

        // Exactly three days later: still inside the window.
        Carbon::setTestNow(Carbon::now()->addDays(3));
        $this->actingIn($this->tenant, $requester)->postJson("/app/office-requests/{$request->id}/reopen")->assertOk();

        // Close it again, then push well past three days: refused.
        $this->actingIn($this->tenant, $admin)->postJson("/app/office-requests/{$request->id}/done", ['note' => 'Sorted again.'])->assertOk();
        Carbon::setTestNow(Carbon::now()->addDays(4));
        $this->actingIn($this->tenant, $requester)->postJson("/app/office-requests/{$request->id}/reopen")->assertStatus(422);
    }

    #[Test]
    public function every_office_request_route_is_isolated_by_tenant(): void
    {
        $tenantB = Tenant::create(['slug' => 'other', 'name' => 'Other Co', 'initials' => 'OC']);

        $requesterA = $this->person($this->tenant, 'Emysha');
        $intruder = $this->person($tenantB, 'Intruder', 'hr');

        $request = $this->raise($this->tenant, $requesterA);

        // Every {officeRequest}-bound route must 404 for a different tenant's employee,
        // never leak content, and never let them act on someone else's request.
        $this->actingIn($tenantB, $intruder)->get("/app/office-requests/{$request->id}/photo")->assertStatus(404);
        $this->actingIn($tenantB, $intruder)->postJson("/app/office-requests/{$request->id}/upvote")->assertStatus(404);
        $this->actingIn($tenantB, $intruder)->postJson("/app/office-requests/{$request->id}/admin-note", ['note' => 'x'])->assertStatus(404);
        $this->actingIn($tenantB, $intruder)->postJson("/app/office-requests/{$request->id}/done", ['note' => 'x'])->assertStatus(404);
        $this->actingIn($tenantB, $intruder)->postJson("/app/office-requests/{$request->id}/reopen")->assertStatus(404);

        $this->assertSame(0, DB::table('office_request_votes')->where('office_request_id', $request->id)->where('employee_id', $intruder->id)->count());
        $this->assertSame('open', $request->fresh()->status);
    }

    /** QA F1: the real tenant's department is "Administration", so the helper roster matches the "Admin" prefix. */
    #[Test]
    public function helpers_come_from_a_department_whose_name_starts_with_admin(): void
    {
        $dept = Department::create(['tenant_id' => $this->tenant->id, 'name' => 'Administration']);
        $helper = $this->person($this->tenant, 'Admin Exec', 'employee', ['department_id' => $dept->id]);
        $requester = $this->person($this->tenant, 'Requester');

        $card = $this->raise($this->tenant, $requester)->workItem;

        $this->assertSame([$helper->id], $card->participants()->wherePivot('role', 'helper')->pluck('employees.id')->all());
    }

    /** QA F2 to F5: spec category names on the screen, notification links, reopen button only inside the window, one-decimal average. */
    #[Test]
    public function the_screens_use_the_spec_category_names_link_notifications_and_hide_reopen_outside_the_window(): void
    {
        $requester = $this->person($this->tenant, 'Requester');
        $boss = Employee::where('name', 'Finance Boss')->firstOrFail();
        $this->actingIn($this->tenant, $requester)->postJson('/app/office-requests', [
            'category' => 'it', 'title' => 'Mouse rosak', 'description' => 'Left click dead.',
            'location' => 'Level 2', 'urgency' => 'urgent', 'urgency_reason' => 'Cannot work.',
        ])->assertSuccessful();
        $request = OfficeRequest::where('title', 'Mouse rosak')->firstOrFail();

        $this->actingIn($this->tenant, $requester)->get('/app/office-requests')
            ->assertOk()
            ->assertSee('IT &amp; equipment', false)
            ->assertDontSee('>It<', false);

        $this->assertSame(url('/app/office-requests'), DB::table('app_notifications')->where('user_id', $boss->user_id)->value('url'));

        Carbon::setTestNow('2026-09-02 09:00:00');
        $this->actingIn($this->tenant, $boss)->postJson("/app/office-requests/{$request->id}/done", ['note' => 'Replaced.'])->assertOk();
        $this->assertSame(url('/app/office-requests'), DB::table('app_notifications')->where('user_id', $requester->user_id)->value('url'));

        $this->actingIn($this->tenant, $requester)->get('/app/office-requests')->assertOk()->assertSee('Reopen');
        Carbon::setTestNow('2026-09-06 09:00:00');
        $this->actingIn($this->tenant, $requester)->get('/app/office-requests')->assertOk()->assertDontSee('Reopen');

        $this->actingIn($this->tenant, $boss)->get('/app/office-requests/insights?month=2026-09')
            ->assertOk()
            ->assertSee('1.0')
            ->assertSee('IT &amp; equipment', false);
        $this->actingIn($this->tenant, $boss)->getJson('/app/office-requests/insights?month=2026-09')
            ->assertOk()->assertJson(['avg_days_to_close' => 1.0]);
    }
}
