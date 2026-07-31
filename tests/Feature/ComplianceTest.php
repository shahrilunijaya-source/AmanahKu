<?php

namespace Tests\Feature;

use App\Http\Controllers\ComplianceController;
use App\Models\ComplianceItem;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Feature coverage for the Compliance / licence-tracking module.
 * Harness (setUp / actingInTenant / hrActor) copied from LoanTest.
 */
class ComplianceTest extends TestCase
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

    private function item(array $overrides = []): ComplianceItem
    {
        return ComplianceItem::create(array_merge([
            'tenant_id' => $this->tenant->id, 'employee_id' => $this->employee->id,
            'type' => 'license', 'name' => 'Forklift License',
            'expires_at' => now()->addDays(120),
        ], $overrides));
    }

    public function test_privileged_user_creates_an_item(): void
    {
        // Arrange
        $hr = $this->hrActor();

        // Act
        $response = $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])->post('/app/compliance', [
            'employee_id' => $this->employee->id,
            'type' => 'certification',
            'name' => 'First Aid Certification',
            'identifier' => 'FA-001',
            'expires_at' => now()->addDays(200)->toDateString(),
        ]);

        // Assert
        $response->assertRedirect();
        $this->assertDatabaseHas('compliance_items', [
            'tenant_id' => $this->tenant->id,
            'employee_id' => $this->employee->id,
            'type' => 'certification',
            'name' => 'First Aid Certification',
        ]);
    }

    public function test_renew_bumps_the_expiry_date(): void
    {
        // Arrange
        $hr = $this->hrActor();
        $item = $this->item(['expires_at' => now()->subDays(10)]);
        $newDate = now()->addDays(365)->toDateString();

        // Act
        $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->post("/app/compliance/{$item->id}/renew", ['expires_at' => $newDate, 'reissue' => 1])
            ->assertRedirect();

        // Assert
        $fresh = $item->fresh();
        $this->assertSame($newDate, $fresh->expires_at->toDateString());
        $this->assertSame(now()->toDateString(), $fresh->issued_at->toDateString());
    }

    public function test_destroy_deletes_the_item(): void
    {
        // Arrange
        $hr = $this->hrActor();
        $item = $this->item();

        // Act
        $this->actingAs($hr)->withSession(['current_tenant' => $this->tenant->id])
            ->delete("/app/compliance/{$item->id}")
            ->assertRedirect();

        // Assert
        $this->assertDatabaseMissing('compliance_items', ['id' => $item->id]);
    }

    public function test_plain_employee_cannot_write(): void
    {
        // Arrange
        $item = $this->item();

        // Act + Assert — store, renew, destroy all forbidden for a plain employee.
        $this->actingInTenant()->post('/app/compliance', [
            'employee_id' => $this->employee->id, 'type' => 'license',
            'name' => 'Nope', 'expires_at' => now()->addDays(30)->toDateString(),
        ])->assertForbidden();

        $this->actingInTenant()->post("/app/compliance/{$item->id}/renew", [
            'expires_at' => now()->addDays(365)->toDateString(),
        ])->assertForbidden();

        $this->actingInTenant()->delete("/app/compliance/{$item->id}")->assertForbidden();

        $this->assertDatabaseHas('compliance_items', ['id' => $item->id]);
    }

    public function test_expiry_accessors_return_the_correct_bucket(): void
    {
        // Arrange — a known date 50 days out → ≤60 bucket, amber.
        $item = $this->item(['expires_at' => Carbon::now()->addDays(50)]);

        // Act + Assert
        $this->assertSame(50, $item->days_to_expiry);
        $this->assertSame('60', $item->expiry_bucket);
        $this->assertSame('amber', $item->expiry_color);

        // An expired item → negative days, expired bucket, error colour.
        $expired = $this->item(['expires_at' => Carbon::now()->subDays(5)]);
        $this->assertSame(-5, $expired->days_to_expiry);
        $this->assertSame('expired', $expired->expiry_bucket);
        $this->assertSame('error', $expired->expiry_color);
    }

    public function test_non_privileged_screen_data_sees_only_own_items(): void
    {
        // Arrange — one item for the actor, one for a colleague.
        $colleague = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Colleague', 'status' => 'active', 'workload' => 'green',
        ]);
        $this->item(['name' => 'Mine']);
        $this->item(['employee_id' => $colleague->id, 'name' => 'Theirs']);

        // Act — drive screenData directly with a plain-employee request.
        $this->actingInTenant();
        $request = Request::create('/app/compliance', 'GET');
        $request->attributes->set('tenantRole', 'employee');
        $data = app(ComplianceController::class)->screenData($request, $this->employee);

        // Assert — only own item, no workforce-wide buckets, no recipients.
        $this->assertCount(1, $data['items']);
        $this->assertSame('Mine', $data['items']->first()->name);
        $this->assertEmpty($data['buckets']);
        $this->assertCount(0, $data['recipients']);
    }
}
