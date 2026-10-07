<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Attendance\ClockService;
use App\Attendance\ScheduleResolver;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\WorkDayRule;
use App\Tenancy\CurrentTenant;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SpecialDayClockTest extends TestCase
{
    use RefreshDatabase;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        app(CurrentTenant::class)->set($tenant);
        $branch = Branch::create([
            'tenant_id' => $tenant->id, 'name' => 'HQ', 'latitude' => 3.10, 'longitude' => 101.60,
            'radius_m' => 200, 'work_start' => '09:00:00', 'work_end' => '18:00:00', 'min_hours' => 8,
        ]);
        $this->employee = Employee::create([
            'tenant_id' => $tenant->id, 'name' => 'Clocker', 'status' => 'active', 'workload' => 'green',
            'branch_id' => $branch->id,
        ]);
        WorkDayRule::factory()->create(['tenant_id' => $tenant->id]);
    }

    protected function tearDown(): void
    {
        app(CurrentTenant::class)->set(null);
        parent::tearDown();
    }

    public function test_resolver_swaps_in_the_rule_hours_only_on_the_special_day(): void
    {
        $resolver = new ScheduleResolver;

        $special = $resolver->resolve($this->employee, Carbon::parse('2026-10-03'));
        $this->assertSame(['09:00', '13:00', 4.0], [$special->workStart, $special->workEnd, $special->minHours]);

        $normal = $resolver->resolve($this->employee, Carbon::parse('2026-10-02'));
        $this->assertSame(['09:00', '18:00', 8.0], [$normal->workStart, $normal->workEnd, $normal->minHours]);

        $secondSaturday = $resolver->resolve($this->employee, Carbon::parse('2026-10-10'));
        $this->assertSame(['09:00', '18:00', 8.0], [$secondSaturday->workStart, $secondSaturday->workEnd, $secondSaturday->minHours]);
    }

    public function test_leaving_at_the_rule_end_needs_no_reason(): void
    {
        $svc = new ClockService(new ScheduleResolver);

        $in = $svc->clockIn($this->employee, 3.1001, 101.6001, null, 'p.jpg', Carbon::parse('2026-10-03 08:56:00'));
        $this->assertSame('ok', $in['status']);

        $out = $svc->clockOut($this->employee, 3.1001, 101.6001, null, 'p.jpg', Carbon::parse('2026-10-03 13:04:00'));
        $this->assertSame('ok', $out['status']);
        $record = $this->employee->attendanceRecords()->first();
        $this->assertNotContains('early_out', $record->flags ?? []);
        $this->assertNotContains('short_hours', $record->flags ?? []);
    }

    public function test_leaving_before_the_rule_end_needs_a_reason(): void
    {
        $svc = new ClockService(new ScheduleResolver);
        $svc->clockIn($this->employee, 3.1001, 101.6001, null, 'p.jpg', Carbon::parse('2026-10-03 08:56:00'));

        $out = $svc->clockOut($this->employee, 3.1001, 101.6001, null, 'p.jpg', Carbon::parse('2026-10-03 12:20:00'));
        $this->assertSame('needs_justification', $out['status']);
    }

    /** The reason only unblocks the clock out: leaving early on the special day is still flagged. */
    public function test_leaving_early_with_a_reason_still_flags_the_record(): void
    {
        $svc = new ClockService(new ScheduleResolver);
        $svc->clockIn($this->employee, 3.1001, 101.6001, null, 'p.jpg', Carbon::parse('2026-10-03 08:56:00'));

        $out = $svc->clockOut($this->employee, 3.1001, 101.6001, 'Left early', 'p.jpg', Carbon::parse('2026-10-03 12:20:00'));

        $this->assertSame('ok', $out['status']);
        $record = $this->employee->attendanceRecords()->first();
        $this->assertContains('early_out', $record->flags);
        $this->assertContains('short_hours', $record->flags);
    }

    /** The migration turns the old tot_saturday flag into a rule 4 hours from the branch's normal start. */
    public function test_migration_seeds_the_tot_rule_from_the_branch_start(): void
    {
        $tenant = Tenant::create(['slug' => 'tot', 'name' => 'Tot', 'initials' => 'TT', 'tot_saturday' => true]);
        Branch::create([
            'tenant_id' => $tenant->id, 'name' => 'HQ', 'latitude' => 3.10, 'longitude' => 101.60,
            'radius_m' => 200, 'work_start' => '10:00:00', 'work_end' => '19:00:00', 'min_hours' => 8,
        ]);
        Schema::drop('work_day_rules');

        (require database_path('migrations/2026_10_03_120000_create_work_day_rules_table.php'))->up();

        $rule = WorkDayRule::withoutGlobalScopes()->where('tenant_id', $tenant->id)->sole();
        $this->assertSame(['10:00', '14:00', 'half'], [$rule->startHhmm(), $rule->endHhmm(), $rule->counts]);
        $this->assertFalse((bool) $tenant->fresh()->tot_saturday);
    }
}
