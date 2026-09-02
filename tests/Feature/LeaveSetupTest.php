<?php

namespace Tests\Feature;

use App\Http\Controllers\SetupController;
use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * HR "Leave Setup" — the carry-forward opening-balance grid. Proves HR can seed the
 * per-type leave_balances rows, that non-privileged users are gated out, and that a
 * forged foreign id in the payload can never write a balance across tenants.
 */
class LeaveSetupTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $employee;

    private User $hr;

    private Employee $staff;

    private LeaveType $annual;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);

        $this->employee = User::create(['name' => 'Worker', 'email' => 'worker@example.com', 'password' => Hash::make('password')]);
        $this->employee->tenants()->attach($this->tenant->id, ['role' => 'employee']);

        $this->hr = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $this->hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);

        $this->staff = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->employee->id,
            'name' => 'Worker', 'status' => 'active', 'workload' => 'green',
        ]);

        $this->annual = LeaveType::create(['tenant_id' => $this->tenant->id, 'name' => 'Annual', 'entitlement' => 18]);
    }

    private function asHr(): self
    {
        $this->actingAs($this->hr)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    public function test_a_type_that_deducts_from_another_gets_no_opening_balance(): void
    {
        $emergency = LeaveType::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Emergency', 'entitlement' => 0,
            'is_unplanned' => true, 'deducts_from_leave_type_id' => $this->annual->id,
        ]);

        $this->asHr()->post('/app/leave-setup', [
            'balances' => [$this->staff->id => [$this->annual->id => 12, $emergency->id => 5]],
        ])->assertRedirect();

        // Annual is the only balance that exists — Emergency spends it, so a row of its
        // own would be a number nothing ever reads.
        $this->assertEqualsWithDelta(12.0, (float) LeaveBalance::where('leave_type_id', $this->annual->id)->value('balance'), 0.001);
        $this->assertDatabaseMissing('leave_balances', ['leave_type_id' => $emergency->id]);

        // The grid shows the Annual figure in that column instead of an editable cell.
        $this->asHr()->get('/app/leave-setup')->assertOk()->assertSee('off Annual');
    }

    public function test_unpaid_leave_carries_no_quota_and_gets_no_opening_balance(): void
    {
        $unpaid = LeaveType::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Unpaid', 'entitlement' => 0, 'is_unpaid' => true,
        ]);

        $this->asHr()->post('/app/leave-setup', [
            'balances' => [$this->staff->id => [$this->annual->id => 12, $unpaid->id => 5]],
        ])->assertRedirect();

        // Unpaid is not an entitlement — it is salary not paid for a day not worked, so
        // there is no running total to open and everyone can take it.
        $this->assertDatabaseMissing('leave_balances', ['leave_type_id' => $unpaid->id]);
        $this->asHr()->get('/app/leave-setup')->assertOk()->assertSee('no quota, open to all');

        // Nor can an entitlement be typed back onto the type itself.
        $this->asHr()->post("/app/leave-setup/types/{$unpaid->id}", [
            'name' => 'Unpaid', 'entitlement' => 5,
        ])->assertRedirect();

        $this->assertEqualsWithDelta(0.0, (float) $unpaid->fresh()->entitlement, 0.001);
    }

    public function test_the_two_setup_steps_open_on_their_own_tab(): void
    {
        // Both company-setup steps embed the same screen, so the holiday one is only
        // useful if it carries the tab that actually holds the holiday calendar.
        $steps = app(SetupController::class)->stepDefs();

        $this->assertSame('leave-setup', $steps['leave_types']['screen']);
        $this->assertSame('leave-setup', $steps['holidays']['screen']);
        $this->assertSame(['tab' => 'holidays'], $steps['holidays']['query']);

        // And the screen has to honour it, not just be handed it.
        $this->asHr()->get('/app/leave-setup?tab=holidays')->assertOk()->assertSee("tab: 'holidays'", false);
    }

    public function test_the_balances_grid_renders_a_tick_box_per_person_and_type(): void
    {
        // The controller side of the tick is covered below, but the tick is useless if the
        // grid never draws it — this is the assertion that catches the markup going missing.
        $response = $this->asHr()->get('/app/leave-setup');

        $response->assertOk();
        $response->assertSee('name="applies['.$this->staff->id.']['.$this->annual->id.']"', false);
        // The hidden 0 must ride in front of the box, or an unticked cell posts nothing.
        $response->assertSee('type="hidden" name="applies['.$this->staff->id.']['.$this->annual->id.']" value="0"', false);
    }

    public function test_unticking_a_type_removes_the_persons_balance(): void
    {
        LeaveBalance::create(['employee_id' => $this->staff->id, 'leave_type_id' => $this->annual->id, 'balance' => 12]);

        // An intern gets no annual leave: HR clears the tick and the row goes.
        $this->asHr()->post('/app/leave-setup', [
            'applies' => [$this->staff->id => [$this->annual->id => 0]],
            'balances' => [$this->staff->id => [$this->annual->id => 12]],
        ])->assertRedirect();

        $this->assertDatabaseMissing('leave_balances', [
            'employee_id' => $this->staff->id, 'leave_type_id' => $this->annual->id,
        ]);
    }

    public function test_ticking_a_type_with_no_days_still_opens_a_row(): void
    {
        // The tick is the eligibility: without a row the type is never offered, so a
        // person entitled to it but carrying nothing forward still needs one at zero.
        $this->asHr()->post('/app/leave-setup', [
            'applies' => [$this->staff->id => [$this->annual->id => 1]],
            'balances' => [$this->staff->id => [$this->annual->id => '']],
        ])->assertRedirect();

        $this->assertEqualsWithDelta(0.0, (float) LeaveBalance::where('employee_id', $this->staff->id)
            ->where('leave_type_id', $this->annual->id)->value('balance'), 0.001);
    }

    public function test_a_type_the_person_is_not_eligible_for_cannot_be_applied_for(): void
    {
        $this->actingAs($this->employee)->withSession(['current_tenant' => $this->tenant->id])
            ->post('/app/leave', [
                'leave_type_id' => $this->annual->id,
                'date_from' => now()->addDays(10)->toDateString(),
                'date_to' => now()->addDays(10)->toDateString(),
                'reason' => 'Family matters.',
            ])->assertSessionHasErrors('leave_type_id');

        $this->assertDatabaseCount('leave_requests', 0);
    }

    public function test_hr_sees_the_leave_setup_screen(): void
    {
        $this->asHr()->get('/app/leave-setup')->assertOk();
    }

    /**
     * A granted type keeps no running total: HR books those days outright, so the grid
     * offers no cell to open one and a forged figure in the payload writes nothing.
     */
    public function test_a_granted_type_gets_no_opening_balance(): void
    {
        $replacement = LeaveType::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Replacement', 'entitlement' => 4,
            'is_hr_granted_only' => true,
        ]);

        $this->asHr()->post('/app/leave-setup', [
            'balances' => [$this->staff->id => [$this->annual->id => 12, $replacement->id => 4]],
        ])->assertRedirect();

        $this->assertEqualsWithDelta(12.0, (float) LeaveBalance::where('leave_type_id', $this->annual->id)->value('balance'), 0.001);
        $this->assertDatabaseMissing('leave_balances', ['leave_type_id' => $replacement->id]);

        $html = $this->asHr()->get('/app/leave-setup')->assertOk()->getContent();
        $this->assertStringContainsString('granted, no balance', $html);
        $this->assertStringNotContainsString('balances['.$this->staff->id.']['.$replacement->id.']', $html);
    }

    /**
     * An HR-granted type (Replacement) is missing from the employee's Apply form, so the
     * only way the day gets booked is the Record card here. It appears only once such a
     * type exists — a tenant without one has nothing to record.
     */
    public function test_the_record_card_appears_only_for_hr_granted_types(): void
    {
        $this->asHr()->get('/app/leave-setup')->assertOk()->assertDontSee(route('leave.record'), false);

        LeaveType::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Replacement', 'entitlement' => 4,
            'is_hr_granted_only' => true,
        ]);

        $this->asHr()->get('/app/leave-setup')->assertOk()
            ->assertSee('Book a replacement day')
            ->assertSee(route('leave.record'), false);
    }

    public function test_the_balance_grid_is_searchable_by_nickname(): void
    {
        $this->staff->update(['nickname' => 'wory']);

        $html = $this->asHr()->get('/app/leave-setup')->assertOk()->getContent();

        // The search haystack Alpine filters on: display name, legal name and position,
        // lower-cased, one entry per row.
        $this->assertStringContainsString('wory worker', $html);
        // The row shows the name people actually say, with the legal name alongside it.
        $this->assertStringContainsString('>Wory</div>', $html);
    }

    public function test_employee_cannot_see_the_leave_setup_screen(): void
    {
        $this->actingAs($this->employee)->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/leave-setup')->assertForbidden();
    }

    public function test_hr_sets_an_opening_balance(): void
    {
        $this->asHr()->post('/app/leave-setup', [
            'balances' => [$this->staff->id => [$this->annual->id => 14]],
        ])->assertRedirect();

        $this->assertDatabaseHas('leave_balances', [
            'employee_id' => $this->staff->id,
            'leave_type_id' => $this->annual->id,
            'balance' => 14.0,
        ]);
    }

    public function test_saving_overwrites_an_existing_balance_and_blanks_are_left_untouched(): void
    {
        $sick = LeaveType::create(['tenant_id' => $this->tenant->id, 'name' => 'Sick', 'entitlement' => 14]);
        LeaveBalance::create(['employee_id' => $this->staff->id, 'leave_type_id' => $this->annual->id, 'balance' => 5]);
        LeaveBalance::create(['employee_id' => $this->staff->id, 'leave_type_id' => $sick->id, 'balance' => 8]);

        // Overwrite annual to 12; leave the sick cell blank — it must keep its 8.
        $this->asHr()->post('/app/leave-setup', [
            'balances' => [$this->staff->id => [$this->annual->id => 12, $sick->id => '']],
        ])->assertRedirect();

        $this->assertEqualsWithDelta(12.0, (float) LeaveBalance::where('leave_type_id', $this->annual->id)->value('balance'), 0.001);
        $this->assertEqualsWithDelta(8.0, (float) LeaveBalance::where('leave_type_id', $sick->id)->value('balance'), 0.001);
    }

    public function test_employee_cannot_save_balances(): void
    {
        $this->actingAs($this->employee)->withSession(['current_tenant' => $this->tenant->id])
            ->post('/app/leave-setup', [
                'balances' => [$this->staff->id => [$this->annual->id => 99]],
            ])->assertForbidden();

        $this->assertDatabaseCount('leave_balances', 0);
    }

    public function test_a_foreign_tenant_leave_type_id_is_ignored(): void
    {
        // A leave type that belongs to a DIFFERENT tenant.
        $other = Tenant::create(['slug' => 'other', 'name' => 'Other', 'initials' => 'OT']);
        $foreignType = LeaveType::create(['tenant_id' => $other->id, 'name' => 'Annual', 'entitlement' => 18]);

        $this->asHr()->post('/app/leave-setup', [
            'balances' => [$this->staff->id => [$foreignType->id => 50]],
        ])->assertRedirect();

        // The forged id was whitelisted out — no balance written for the foreign type.
        $this->assertDatabaseMissing('leave_balances', [
            'employee_id' => $this->staff->id,
            'leave_type_id' => $foreignType->id,
        ]);
    }
}
