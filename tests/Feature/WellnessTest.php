<?php

namespace Tests\Feature;

use App\Http\Controllers\WellnessController;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WellnessCheckin;
use App\Models\WellnessRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Feature coverage for the confidential Wellness / EAP module.
 *
 * Harness (setUp / actingInTenant / privilegedActor) copied from CaseTest. The
 * confidentiality cases are the heart of this suite: HR must never see an
 * individual's pulse rows (only aggregates), and a plain employee must never see
 * another employee's check-ins or requests.
 */
class WellnessTest extends TestCase
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

    /** An HR user + their employee profile in the same tenant. */
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

    /** Build a Request carrying the given tenant role + employee, as middleware would. */
    private function requestAs(string $role, ?Employee $employee): Request
    {
        $request = Request::create('/app/wellness', 'GET');
        $request->attributes->set('tenantRole', $role);
        $request->attributes->set('employee', $employee);

        return $request;
    }

    // ── Employee logs a check-in (own row) ────────────────────────

    public function test_employee_logs_a_private_check_in(): void
    {
        // Act
        $response = $this->actingInTenant()->post('/app/wellness/checkin', [
            'mood' => 4,
            'stress' => 2,
            'note' => 'Coping well.',
        ]);

        // Assert
        $response->assertRedirect();
        $this->assertDatabaseHas('wellness_checkins', [
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'mood' => 4,
            'stress' => 2,
            'note' => 'Coping well.',
        ]);
    }

    public function test_check_in_rejects_out_of_range_scales(): void
    {
        // Act
        $response = $this->actingInTenant()->post('/app/wellness/checkin', [
            'mood' => 7,
            'stress' => 0,
        ]);

        // Assert
        $response->assertSessionHasErrors(['mood', 'stress']);
        $this->assertDatabaseMissing('wellness_checkins', ['employee_id' => $this->employee->id]);
    }

    // ── Employee submits a wellness request ───────────────────────

    public function test_employee_submits_a_wellness_request(): void
    {
        // Act
        $response = $this->actingInTenant()->post('/app/wellness/request', [
            'topic' => 'Mental Health',
            'message' => 'I would like a confidential chat.',
            'urgency' => 'normal',
        ]);

        // Assert
        $response->assertRedirect();
        $this->assertDatabaseHas('wellness_requests', [
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'topic' => 'Mental Health',
            'urgency' => 'normal',
            'status' => 'open',
        ]);
    }

    // ── HR resolves a request (status changes) ────────────────────

    public function test_hr_resolves_a_request(): void
    {
        // Arrange
        $hr = $this->hrActor();
        $request = WellnessRequest::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'message' => 'Please can we talk.',
            'urgency' => 'high',
            'status' => 'open',
        ]);

        // Act
        $response = $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/wellness/requests/{$request->id}", ['status' => 'acknowledged']);

        // Assert
        $response->assertRedirect();
        $fresh = $request->fresh();
        $this->assertSame('acknowledged', $fresh->status);
        $this->assertNotNull($fresh->handled_at);
    }

    // ── Authorization: non-privileged writes are forbidden ────────

    public function test_plain_employee_cannot_store_a_resource(): void
    {
        // Act
        $response = $this->actingInTenant()->post('/app/wellness/resources', [
            'title' => 'Sneaky resource',
            'category' => 'Mental Health',
            'description' => 'Should never be created.',
        ]);

        // Assert
        $response->assertForbidden();
        $this->assertDatabaseMissing('eap_resources', ['title' => 'Sneaky resource']);
    }

    public function test_plain_employee_cannot_resolve_a_request(): void
    {
        // Arrange
        $request = WellnessRequest::create([
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'message' => 'Private.',
            'urgency' => 'normal',
            'status' => 'open',
        ]);

        // Act
        $response = $this->actingInTenant()->post("/app/wellness/requests/{$request->id}", ['status' => 'closed']);

        // Assert
        $response->assertForbidden();
        $this->assertSame('open', $request->fresh()->status);
    }

    // ── CONFIDENTIALITY: HR never sees individual pulse rows ───────

    public function test_hr_screen_data_exposes_only_aggregates_never_individual_check_ins(): void
    {
        // Arrange — two staff with distinctive private notes.
        $other = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Other', 'status' => 'active', 'workload' => 'green',
        ]);
        WellnessCheckin::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'mood' => 2, 'stress' => 5, 'note' => 'SECRET-NOTE-DEMO', 'checkin_date' => now()->toDateString(),
        ]);
        WellnessCheckin::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $other->id,
            'mood' => 4, 'stress' => 2, 'note' => 'SECRET-NOTE-OTHER', 'checkin_date' => now()->toDateString(),
        ]);
        app('App\\Tenancy\\CurrentTenant')->set($this->tenant);

        // Act — HR opens the screen.
        $data = (new WellnessController)->screenData($this->requestAs('hr', null), null);

        // Assert — aggregates are present with signal, but NO individual rows leak.
        $this->assertNotNull($data['aggregate']);
        $this->assertSame(2, $data['aggregate']['count']);
        $this->assertSame(2, $data['aggregate']['participants']);
        $this->assertSame(3.0, $data['aggregate']['avgMood']);
        // Distribution buckets are zero-filled 1..5 and count the two moods (2 and 4).
        $this->assertSame(1, $data['aggregate']['moodDist'][2]);
        $this->assertSame(1, $data['aggregate']['moodDist'][4]);
        $this->assertSame(0, $data['aggregate']['moodDist'][1]);
        // HR's own slice is empty (HR has no check-ins) and there is no all-rows key.
        $this->assertTrue($data['myCheckins']->isEmpty());
        $this->assertArrayNotHasKey('checkins', $data);
        // The private notes must not appear anywhere in the HR data shape.
        $serialized = json_encode($data['aggregate']);
        $this->assertStringNotContainsString('SECRET-NOTE-DEMO', $serialized);
        $this->assertStringNotContainsString('SECRET-NOTE-OTHER', $serialized);
    }

    // ── CONFIDENTIALITY: an employee sees only their own slice ─────

    public function test_employee_cannot_see_another_employees_check_ins_or_requests(): void
    {
        // Arrange — another employee's check-in + request.
        $other = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Other', 'status' => 'active', 'workload' => 'green',
        ]);
        WellnessCheckin::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $other->id,
            'mood' => 5, 'stress' => 1, 'note' => 'OTHER-PRIVATE', 'checkin_date' => now()->toDateString(),
        ]);
        WellnessRequest::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $other->id,
            'message' => 'OTHER-REQUEST', 'urgency' => 'normal', 'status' => 'open',
        ]);
        // The acting employee's own check-in, for contrast.
        WellnessCheckin::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'mood' => 3, 'stress' => 3, 'note' => 'MINE', 'checkin_date' => now()->toDateString(),
        ]);
        app('App\\Tenancy\\CurrentTenant')->set($this->tenant);

        // Act — plain employee opens the screen.
        $data = (new WellnessController)->screenData(
            $this->requestAs('employee', $this->employee),
            $this->employee
        );

        // Assert — only the acting employee's own rows; no aggregates, no inbox.
        $this->assertCount(1, $data['myCheckins']);
        $this->assertSame($this->employee->id, $data['myCheckins']->first()->employee_id);
        $this->assertTrue($data['myRequests']->isEmpty());
        $this->assertNull($data['aggregate']);
        $this->assertTrue($data['inbox']->isEmpty());
        $this->assertFalse($data['privileged']);
    }
}
