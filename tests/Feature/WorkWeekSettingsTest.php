<?php

namespace Tests\Feature;

use App\Http\Controllers\SetupController;
use App\Models\AuditLog;
use App\Models\CompanySetupProgress;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** The Work week card on Company Settings: HR and management save it, staff cannot see or change it. */
class WorkWeekSettingsTest extends TestCase
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

    public function test_hr_saves_a_six_day_week_and_it_is_audited(): void
    {
        $this->actingAsRole('hr')
            ->post(route('admin.workweek.update'), ['work_days' => ['6', '1', '2', '3', '4', '5']])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame([1, 2, 3, 4, 5, 6], $this->tenant->fresh()->work_days);
        $this->assertDatabaseHas('audit_logs', ['action' => 'Updated work week', 'target' => 'Mon, Tue, Wed, Thu, Fri, Sat']);
    }

    public function test_management_can_save_too(): void
    {
        $this->actingAsRole('management')
            ->post(route('admin.workweek.update'), ['work_days' => [2, 3, 4, 5, 6]])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame([2, 3, 4, 5, 6], $this->tenant->fresh()->work_days);
    }

    public function test_at_least_one_working_day_is_required(): void
    {
        $this->actingAsRole('hr')
            ->from('/app/settings')
            ->post(route('admin.workweek.update'), [])
            ->assertSessionHasErrors('work_days');

        $this->assertSame([1, 2, 3, 4, 5], $this->tenant->fresh()->work_days);
    }

    public function test_days_must_be_iso_weekdays_without_repeats(): void
    {
        $this->actingAsRole('hr')
            ->post(route('admin.workweek.update'), ['work_days' => [0, 8]])
            ->assertSessionHasErrors('work_days.0');

        $this->actingAsRole('hr')
            ->post(route('admin.workweek.update'), ['work_days' => [1, 1]])
            ->assertSessionHasErrors('work_days.0');

        $this->assertSame([1, 2, 3, 4, 5], $this->tenant->fresh()->work_days);
    }

    public function test_an_employee_is_refused(): void
    {
        $this->actingAsRole('employee')
            ->post(route('admin.workweek.update'), ['work_days' => [1]])
            ->assertStatus(403);

        $this->assertSame([1, 2, 3, 4, 5], $this->tenant->fresh()->work_days);
        $this->assertSame(0, AuditLog::where('action', 'Updated work week')->count());
    }

    public function test_the_card_renders_for_hr_and_alone_with_section_work_week(): void
    {
        $this->actingAsRole('hr')->get('/app/settings')->assertOk()->assertSee('Work week');
        $this->actingAsRole('hr')->get('/app/settings?section=work_week')
            ->assertOk()
            ->assertSee('Work week')
            ->assertDontSee('name="industry"', false);
    }

    public function test_launch_center_has_a_manual_set_work_week_step_after_profile(): void
    {
        // stepDefs() reads the current tenant for the payroll step; bind it as the middleware would.
        app(CurrentTenant::class)->set($this->tenant);
        $defs = app(SetupController::class)->stepDefs();
        app(CurrentTenant::class)->set(null);
        $keys = array_keys($defs);

        $this->assertContains('work_week', $keys);
        $this->assertSame(array_search('profile', $keys, true) + 1, array_search('work_week', $keys, true));
        $this->assertSame('settings', $defs['work_week']['screen']);
        $this->assertSame(['section' => 'work_week'], $defs['work_week']['query']);
        $this->assertFalse($defs['work_week']['auto']);
        $this->assertSame('basics', $defs['work_week']['domain']);

        // Manual step: HR ticks it by hand.
        $this->actingAsRole('hr')->post(route('setup.step'), ['step' => 'work_week'])->assertRedirect();
    }

    /** @return list<string> the manual Launch Center steps ticked for this company */
    private function tickedSteps(): array
    {
        return CompanySetupProgress::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->value('steps') ?? [];
    }

    /**
     * The setup guide tells HR to click Save work week / Save features. Those are
     * manual Launch Center steps, so saving must tick them or the guide never moves on.
     */
    public function test_saving_the_work_week_ticks_its_launch_center_step_once(): void
    {
        $this->actingAsRole('hr');

        $this->post(route('admin.workweek.update'), ['work_days' => [1, 2, 3, 4, 5]])->assertSessionHasNoErrors();
        $this->post(route('admin.workweek.update'), ['work_days' => [1, 2, 3, 4, 5, 6]])->assertSessionHasNoErrors();

        $this->assertSame(['work_week'], $this->tickedSteps());
    }

    public function test_a_refused_work_week_save_ticks_nothing(): void
    {
        $this->actingAsRole('hr')
            ->post(route('admin.workweek.update'), ['work_days' => []])
            ->assertSessionHasErrors('work_days');

        $this->assertSame([], $this->tickedSteps());
    }

    public function test_saving_the_features_card_ticks_the_modules_step_and_keeps_other_ticks(): void
    {
        $this->actingAsRole('hr');
        $this->post(route('admin.workweek.update'), ['work_days' => [1, 2, 3, 4, 5]]);

        $this->post(route('admin.features.update'), ['features_present' => '1', 'features' => ['module.leave' => '1']])
            ->assertSessionHasNoErrors();
        $this->post(route('admin.features.update'), ['features_present' => '1', 'features' => ['module.leave' => '1']]);

        $this->assertSame(['work_week', 'modules'], $this->tickedSteps());
    }

    public function test_a_settings_save_outside_the_features_card_does_not_tick_modules(): void
    {
        $this->actingAsRole('hr')
            ->post(route('admin.features.update'), ['features' => []])
            ->assertSessionHasNoErrors();

        $this->assertSame([], $this->tickedSteps());
    }
}
