<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkDayRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Special work days on the Work week card: HR and management manage them, staff cannot. */
class WorkDayRuleAdminTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
    }

    private function actingAsRole(string $role): self
    {
        $this->seq++;
        $user = User::create(['name' => ucfirst($role), 'email' => "{$role}{$this->seq}@example.com", 'password' => Hash::make('password')]);
        $user->tenants()->attach($this->tenant->id, ['role' => $role]);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'name' => ucfirst($role), 'status' => 'active', 'workload' => 'green',
        ]);
        $this->actingAs($user)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    /** @return array<string, mixed> */
    private function payload(array $over = []): array
    {
        return $over + ['weekday' => 6, 'weeks' => [4, 2], 'start_time' => '09:00', 'end_time' => '13:00', 'counts' => 'half'];
    }

    private function rule(array $over = []): WorkDayRule
    {
        return WorkDayRule::factory()->create($over + ['tenant_id' => $this->tenant->id]);
    }

    public function test_hr_creates_a_rule_and_it_is_audited(): void
    {
        $this->actingAsRole('hr')
            ->post(route('admin.workdayrules.store'), $this->payload())
            ->assertRedirect()->assertSessionHasNoErrors();

        $rule = WorkDayRule::firstOrFail();
        $this->assertSame([2, 4], $rule->weeks);
        $this->assertSame($this->tenant->id, $rule->tenant_id);
        $this->assertDatabaseHas('audit_logs', ['action' => 'Added special work day']);
    }

    public function test_management_can_update_a_rule(): void
    {
        $rule = $this->rule();

        $this->actingAsRole('management')
            ->put(route('admin.workdayrules.update', $rule), $this->payload(['weeks' => [-1, 1], 'counts' => 'full', 'end_time' => '17:30']))
            ->assertRedirect()->assertSessionHasNoErrors();

        $rule->refresh();
        $this->assertSame([1, -1], $rule->weeks);
        $this->assertSame('full', $rule->counts);
        $this->assertSame('17:30', $rule->endHhmm());
        $this->assertDatabaseHas('audit_logs', ['action' => 'Updated special work day']);
    }

    public function test_a_rule_can_be_saved_again_on_its_own_weekday(): void
    {
        $rule = $this->rule();

        $this->actingAsRole('hr')
            ->put(route('admin.workdayrules.update', $rule), $this->payload())
            ->assertSessionHasNoErrors();
    }

    public function test_hr_deletes_a_rule(): void
    {
        $rule = $this->rule();

        $this->actingAsRole('hr')->delete(route('admin.workdayrules.destroy', $rule))->assertRedirect();

        $this->assertModelMissing($rule);
        $this->assertDatabaseHas('audit_logs', ['action' => 'Removed special work day']);
    }

    public function test_one_rule_per_weekday(): void
    {
        $this->rule(['weekday' => 6]);

        $this->actingAsRole('hr')
            ->post(route('admin.workdayrules.store'), $this->payload(['weekday' => 6]))
            ->assertSessionHasErrors('weekday');

        $this->assertSame(1, WorkDayRule::count());
    }

    public function test_another_company_may_use_the_same_weekday(): void
    {
        $other = Tenant::create(['slug' => 'beta', 'name' => 'Beta', 'initials' => 'BE']);
        WorkDayRule::factory()->create(['tenant_id' => $other->id, 'weekday' => 6]);

        $this->actingAsRole('hr')
            ->post(route('admin.workdayrules.store'), $this->payload(['weekday' => 6]))
            ->assertSessionHasNoErrors();
    }

    public function test_end_must_be_after_start(): void
    {
        $this->actingAsRole('hr')
            ->post(route('admin.workdayrules.store'), $this->payload(['start_time' => '13:00', 'end_time' => '09:00']))
            ->assertSessionHasErrors('end_time');
    }

    public function test_at_least_one_week_and_valid_weeks_are_required(): void
    {
        $this->actingAsRole('hr')
            ->post(route('admin.workdayrules.store'), $this->payload(['weeks' => []]))
            ->assertSessionHasErrors('weeks');

        $this->post(route('admin.workdayrules.store'), $this->payload(['weeks' => [5]]))
            ->assertSessionHasErrors('weeks.0');

        $this->post(route('admin.workdayrules.store'), $this->payload(['weeks' => [2, 2]]))
            ->assertSessionHasErrors('weeks.0');
    }

    public function test_weekday_and_counts_are_checked(): void
    {
        $this->actingAsRole('hr')
            ->post(route('admin.workdayrules.store'), $this->payload(['weekday' => 8, 'counts' => 'quarter']))
            ->assertSessionHasErrors(['weekday', 'counts']);
    }

    public function test_an_employee_is_forbidden(): void
    {
        $rule = $this->rule();
        $this->actingAsRole('employee');

        $this->post(route('admin.workdayrules.store'), $this->payload(['weekday' => 5]))->assertForbidden();
        $this->put(route('admin.workdayrules.update', $rule), $this->payload())->assertForbidden();
        $this->delete(route('admin.workdayrules.destroy', $rule))->assertForbidden();
        $this->assertSame(1, WorkDayRule::count());
    }

    public function test_another_companys_rule_cannot_be_changed_or_removed(): void
    {
        $other = Tenant::create(['slug' => 'beta', 'name' => 'Beta', 'initials' => 'BE']);
        $theirs = WorkDayRule::factory()->create(['tenant_id' => $other->id]);
        $this->actingAsRole('hr');

        $this->assertContains($this->put(route('admin.workdayrules.update', $theirs), $this->payload(['counts' => 'full']))->status(), [403, 404]);
        $this->assertContains($this->delete(route('admin.workdayrules.destroy', $theirs))->status(), [403, 404]);

        $this->assertSame('half', $theirs->fresh()->counts);
    }

    public function test_the_settings_page_carries_the_rule(): void
    {
        $this->rule(['weekday' => 6, 'weeks' => [1], 'start_time' => '09:00', 'end_time' => '13:00']);

        $this->actingAsRole('hr')->get('/app/settings?section=work_week')
            ->assertOk()
            ->assertSee('Special days')
            ->assertSee('workDayRules(', false)
            ->assertSee('Hari khas')
            ->assertSee('13:00');
    }
}
