<?php

namespace Tests\Feature;

use App\Http\Controllers\OffboardingController;
use App\Models\ClearanceItem;
use App\Models\Employee;
use App\Models\OffboardingCase;
use App\Models\Resignation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\OffboardingService;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OffboardingTest extends TestCase
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

    public function test_privileged_user_opens_an_offboarding_case(): void
    {
        $this->actingAs($this->hrActor())->withSession(['current_tenant' => $this->tenant->id])
            ->post('/app/offboarding', [
                'employee_id' => $this->employee->id,
                'last_day' => now()->addDays(14)->toDateString(),
                'reason' => 'resignation',
                'notes' => 'Moving on.',
            ])->assertRedirect();

        $this->assertDatabaseHas('offboarding_cases', [
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'reason' => 'resignation', 'status' => 'in_progress',
        ]);

        $case = OffboardingCase::where('employee_id', $this->employee->id)->first();
        $this->assertNotNull($case);
        $this->assertGreaterThan(0, $case->clearanceItems()->count());
    }

    public function test_employee_cannot_open_an_offboarding_case(): void
    {
        $this->actingInTenant()->post('/app/offboarding', [
            'employee_id' => $this->employee->id,
            'last_day' => now()->addDays(14)->toDateString(),
            'reason' => 'resignation',
        ])->assertForbidden();

        $this->assertDatabaseMissing('offboarding_cases', ['employee_id' => $this->employee->id]);
    }

    public function test_privileged_user_toggles_a_clearance_item(): void
    {
        $case = OffboardingCase::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'last_day' => now()->addDays(10)->toDateString(), 'reason' => 'resignation', 'status' => 'in_progress',
        ]);
        $item = $case->clearanceItems()->create(['department' => 'IT', 'title' => 'Revoke access', 'done' => false, 'sort' => 0]);

        $this->actingAs($this->hrActor())->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/offboarding/items/{$item->id}/toggle")->assertRedirect();

        $this->assertTrue($item->fresh()->done);
    }

    public function test_employee_cannot_toggle_a_clearance_item(): void
    {
        $case = OffboardingCase::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'last_day' => now()->addDays(10)->toDateString(), 'reason' => 'resignation', 'status' => 'in_progress',
        ]);
        $item = $case->clearanceItems()->create(['department' => 'IT', 'title' => 'Revoke access', 'done' => false, 'sort' => 0]);

        $this->actingInTenant()->post("/app/offboarding/items/{$item->id}/toggle")->assertForbidden();

        $this->assertFalse($item->fresh()->done);
    }

    public function test_open_case_creates_a_case_and_seeds_the_standard_checklist(): void
    {
        app(CurrentTenant::class)->set($this->tenant);

        $case = app(OffboardingService::class)->openCase(
            $this->employee, now()->addDays(14)->toDateString(), 'termination',
        );

        $this->assertSame('in_progress', $case->status);
        $this->assertNull($case->resignation_id);
        $this->assertSame(count(OffboardingService::STANDARD_CHECKLIST), $case->clearanceItems()->count());
    }

    public function test_open_case_links_an_existing_unlinked_case_instead_of_duplicating(): void
    {
        app(CurrentTenant::class)->set($this->tenant);

        $existing = OffboardingCase::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'last_day' => now()->addDays(20)->toDateString(), 'reason' => 'termination', 'status' => 'in_progress',
        ]);
        $resignation = Resignation::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'submitted_at' => now(), 'last_working_date' => now()->addDays(30)->toDateString(),
            'notice_days' => 30, 'reason' => 'Growth', 'status' => 'acknowledged',
        ]);

        $case = app(OffboardingService::class)->openCase(
            $this->employee, $resignation->last_working_date, 'resignation', null, $resignation,
        );

        $this->assertSame($existing->id, $case->id);
        $this->assertSame($resignation->id, $case->fresh()->resignation_id);
        $this->assertSame(1, OffboardingCase::where('employee_id', $this->employee->id)->count());
    }

    public function test_open_case_is_idempotent_for_the_same_resignation(): void
    {
        app(CurrentTenant::class)->set($this->tenant);

        $resignation = Resignation::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'submitted_at' => now(), 'last_working_date' => now()->addDays(30)->toDateString(),
            'notice_days' => 30, 'reason' => 'Growth', 'status' => 'acknowledged',
        ]);
        $svc = app(OffboardingService::class);

        $first = $svc->openCase($this->employee, $resignation->last_working_date, 'resignation', null, $resignation);
        $second = $svc->openCase($this->employee, $resignation->last_working_date, 'resignation', null, $resignation);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, OffboardingCase::where('resignation_id', $resignation->id)->count());
    }

    public function test_acknowledging_a_resignation_auto_opens_a_linked_prefilled_case(): void
    {
        $resignation = Resignation::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'submitted_at' => now(), 'last_working_date' => now()->addDays(30)->toDateString(),
            'notice_days' => 30, 'reason' => 'Growth', 'status' => 'submitted',
        ]);

        $this->actingAs($this->hrActor())->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/resignation/{$resignation->id}/acknowledge")->assertRedirect();

        $case = OffboardingCase::where('resignation_id', $resignation->id)->first();
        $this->assertNotNull($case);
        $this->assertSame($this->employee->id, $case->employee_id);
        $this->assertSame('resignation', $case->reason);
        $this->assertSame(now()->addDays(30)->format('Y-m-d'), $case->last_day->format('Y-m-d'));
        $this->assertGreaterThan(0, $case->clearanceItems()->count());
    }

    public function test_acknowledge_links_an_existing_unlinked_case_rather_than_duplicating(): void
    {
        $existing = OffboardingCase::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'last_day' => now()->addDays(20)->toDateString(), 'reason' => 'termination', 'status' => 'in_progress',
        ]);
        $resignation = Resignation::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'submitted_at' => now(), 'last_working_date' => now()->addDays(30)->toDateString(),
            'notice_days' => 30, 'reason' => 'Growth', 'status' => 'submitted',
        ]);

        $this->actingAs($this->hrActor())->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/resignation/{$resignation->id}/acknowledge")->assertRedirect();

        $this->assertSame(1, OffboardingCase::where('employee_id', $this->employee->id)->count());
        $this->assertSame($resignation->id, $existing->fresh()->resignation_id);
    }

    public function test_privileged_user_adds_an_ad_hoc_clearance_item(): void
    {
        $case = OffboardingCase::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'last_day' => now()->addDays(10)->toDateString(), 'reason' => 'resignation', 'status' => 'in_progress',
        ]);
        $case->clearanceItems()->create(['department' => 'IT', 'title' => 'Revoke access', 'done' => false, 'sort' => 0]);

        $this->actingAs($this->hrActor())->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/offboarding/{$case->id}/items", [
                'department' => 'Admin', 'title' => 'Collect parking pass',
            ])->assertRedirect();

        $item = ClearanceItem::where('title', 'Collect parking pass')->first();
        $this->assertNotNull($item);
        $this->assertSame('Admin', $item->department);
        $this->assertFalse($item->done);
        // Seeded item was sort 0 → the new one lands at max+1 so it renders last.
        $this->assertSame(1, $item->sort);
    }

    public function test_add_item_rejects_a_department_outside_the_enum(): void
    {
        $case = OffboardingCase::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'last_day' => now()->addDays(10)->toDateString(), 'reason' => 'resignation', 'status' => 'in_progress',
        ]);

        $this->actingAs($this->hrActor())->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/offboarding/{$case->id}/items", [
                'department' => 'Legal', 'title' => 'Unknown dept',
            ])->assertSessionHasErrors('department');

        $this->assertDatabaseMissing('clearance_items', ['title' => 'Unknown dept']);
    }

    public function test_employee_cannot_add_a_clearance_item(): void
    {
        $case = OffboardingCase::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'last_day' => now()->addDays(10)->toDateString(), 'reason' => 'resignation', 'status' => 'in_progress',
        ]);

        $this->actingInTenant()->post("/app/offboarding/{$case->id}/items", [
            'department' => 'IT', 'title' => 'Sneaky task',
        ])->assertForbidden();

        $this->assertDatabaseMissing('clearance_items', ['title' => 'Sneaky task']);
    }

    public function test_cannot_add_an_item_to_a_completed_case(): void
    {
        $case = OffboardingCase::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'last_day' => now()->subDay()->toDateString(), 'reason' => 'resignation', 'status' => 'completed',
        ]);

        $this->actingAs($this->hrActor())->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/offboarding/{$case->id}/items", [
                'department' => 'IT', 'title' => 'Too late',
            ])->assertForbidden();

        $this->assertDatabaseMissing('clearance_items', ['title' => 'Too late']);
    }

    public function test_privileged_user_removes_a_clearance_item(): void
    {
        $case = OffboardingCase::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'last_day' => now()->addDays(10)->toDateString(), 'reason' => 'resignation', 'status' => 'in_progress',
        ]);
        $item = $case->clearanceItems()->create(['department' => 'IT', 'title' => 'Wrong task', 'done' => false, 'sort' => 0]);

        $this->actingAs($this->hrActor())->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/offboarding/items/{$item->id}/remove")->assertRedirect();

        $this->assertDatabaseMissing('clearance_items', ['id' => $item->id]);
    }

    public function test_employee_cannot_remove_a_clearance_item(): void
    {
        $case = OffboardingCase::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'last_day' => now()->addDays(10)->toDateString(), 'reason' => 'resignation', 'status' => 'in_progress',
        ]);
        $item = $case->clearanceItems()->create(['department' => 'IT', 'title' => 'Keep me', 'done' => false, 'sort' => 0]);

        $this->actingInTenant()->post("/app/offboarding/items/{$item->id}/remove")->assertForbidden();

        $this->assertDatabaseHas('clearance_items', ['id' => $item->id]);
    }

    public function test_cannot_remove_an_item_from_a_completed_case(): void
    {
        $case = OffboardingCase::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'last_day' => now()->subDay()->toDateString(), 'reason' => 'resignation', 'status' => 'completed',
        ]);
        $item = $case->clearanceItems()->create(['department' => 'IT', 'title' => 'Frozen', 'done' => true, 'sort' => 0]);

        $this->actingAs($this->hrActor())->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/offboarding/items/{$item->id}/remove")->assertForbidden();

        $this->assertDatabaseHas('clearance_items', ['id' => $item->id]);
    }

    /**
     * A completed case is a frozen historical record. toggleItem must reject a direct/replayed
     * POST just like addItem/removeItem — otherwise the archived outstanding-count is falsifiable.
     */
    public function test_cannot_toggle_an_item_on_a_completed_case(): void
    {
        $case = OffboardingCase::create([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'last_day' => now()->subDay()->toDateString(), 'reason' => 'resignation', 'status' => 'completed',
        ]);
        $item = $case->clearanceItems()->create(['department' => 'IT', 'title' => 'Frozen', 'done' => false, 'sort' => 0]);

        $this->actingAs($this->hrActor())->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/offboarding/items/{$item->id}/toggle")->assertForbidden();

        $this->assertFalse($item->fresh()->done);
    }

    /**
     * A director is a management super-set (Permissions::effectiveRole) and must receive the
     * privileged read/UI surface — not just the write endpoints. Regression for the raw
     * in_array role check that silently denied directors the whole offboarding view.
     */
    public function test_director_receives_the_privileged_offboarding_view(): void
    {
        $director = User::create(['name' => 'Dir', 'email' => 'dir@example.com', 'password' => Hash::make('password')]);
        $director->tenants()->attach($this->tenant->id, ['role' => 'director']);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $director->id,
            'name' => 'Dir', 'status' => 'active', 'workload' => 'green',
        ]);
        app(CurrentTenant::class)->set($this->tenant);

        $request = Request::create('/app/offboarding', 'GET');
        $request->setUserResolver(fn () => $director);
        $request->attributes->set('tenantRole', 'director');

        $data = app(OffboardingController::class)->screenData($request, null);

        $this->assertTrue($data['privileged'], 'Director should be treated as privileged.');
        $this->assertTrue($data['employees']->isNotEmpty(), 'Privileged view must expose the employee list.');
    }
}
