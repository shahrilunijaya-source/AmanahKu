<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\PublicHoliday;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Leave types are managed on the Leave Setup screen (previously there was no UI at all,
 * so a fresh company could never satisfy the launch-critical "configure leave types"
 * step). Covers the one-click Malaysian starter set, add/edit, and the in-use delete guard.
 */
class LeaveTypeSetupTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $this->hr = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $this->hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);
        Employee::create(['tenant_id' => $this->tenant->id, 'user_id' => $this->hr->id, 'name' => 'Boss', 'status' => 'active', 'workload' => 'green']);
    }

    private function actingHr(): self
    {
        $this->actingAs($this->hr)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    public function test_load_standard_set_seeds_the_malaysian_types_and_is_idempotent(): void
    {
        $this->actingHr()->post(route('leave.types.standard'))->assertRedirect();

        $this->assertSame(10, LeaveType::where('tenant_id', $this->tenant->id)->count());
        $annual = LeaveType::where('name', 'Annual')->firstOrFail();
        $emergency = LeaveType::where('name', 'Emergency')->firstOrFail();
        // Emergency has no entitlement of its own — it spends Annual.
        $this->assertSame($annual->id, $emergency->deducts_from_leave_type_id);

        // Running again adds nothing (skips existing names).
        $this->actingHr()->post(route('leave.types.standard'))->assertRedirect();
        $this->assertSame(10, LeaveType::where('tenant_id', $this->tenant->id)->count());
    }

    public function test_add_leave_type_stores_fields(): void
    {
        $this->actingHr()->post(route('leave.types.store'), [
            'name' => 'Study', 'entitlement' => 5, 'min_notice_days' => 14, 'requires_attachment' => 1,
        ])->assertRedirect();

        $type = LeaveType::where('name', 'Study')->firstOrFail();
        $this->assertEquals(5.0, $type->entitlement);
        $this->assertSame(14, $type->min_notice_days);
        $this->assertTrue($type->requires_attachment);
    }

    public function test_delete_removes_an_unused_type_but_blocks_one_in_use(): void
    {
        app(CurrentTenant::class)->set($this->tenant);

        $unused = LeaveType::create(['tenant_id' => $this->tenant->id, 'name' => 'Spare', 'entitlement' => 0]);
        $this->actingHr()->post(route('leave.types.delete', $unused))->assertRedirect();
        $this->assertDatabaseMissing('leave_types', ['id' => $unused->id]);

        // A type with an opening balance carries history — delete must be refused.
        $used = LeaveType::create(['tenant_id' => $this->tenant->id, 'name' => 'Annual', 'entitlement' => 16]);
        $emp = Employee::where('tenant_id', $this->tenant->id)->firstOrFail();
        LeaveBalance::create(['employee_id' => $emp->id, 'leave_type_id' => $used->id, 'balance' => 10]);

        $this->actingHr()->post(route('leave.types.delete', $used))->assertRedirect();
        $this->assertDatabaseHas('leave_types', ['id' => $used->id]);
    }

    public function test_load_standard_holidays_seeds_the_2026_set_idempotently(): void
    {
        $this->actingHr()->post(route('holiday.standard'))->assertRedirect();

        $this->assertSame(15, PublicHoliday::where('tenant_id', $this->tenant->id)->count());
        $nationalDay = PublicHoliday::where('tenant_id', $this->tenant->id)->where('name', 'National Day')->firstOrFail();
        $this->assertSame('2026-08-31', $nationalDay->date->toDateString());

        $this->actingHr()->post(route('holiday.standard'))->assertRedirect();
        $this->assertSame(15, PublicHoliday::where('tenant_id', $this->tenant->id)->count());
    }

    public function test_add_and_delete_public_holiday(): void
    {
        $this->actingHr()->post(route('holiday.store'), ['name' => 'Company Day', 'date' => '2026-07-01'])->assertRedirect();
        $h = PublicHoliday::where('name', 'Company Day')->firstOrFail();

        $this->actingHr()->post(route('holiday.delete', $h))->assertRedirect();
        $this->assertDatabaseMissing('public_holidays', ['id' => $h->id]);
    }
}
