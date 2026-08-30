<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TimesheetCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A company's effort types are seeded, not typed in. The capture screen has no category
 * picker any more — its rows come from board cards — so a tenant with no categories
 * cannot cost a single hour, and nobody would find out until a week failed to submit.
 */
class TimesheetCategorySeedingTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
    }

    private function names(): array
    {
        return TimesheetCategory::withoutGlobalScope('tenant')
            ->where('tenant_id', $this->tenant->id)
            ->orderBy('sort')
            ->pluck('name')
            ->all();
    }

    /**
     * The delivery types, the overhead types, Others, and the two LockedDays writes. The
     * overhead half is seeded because the board asks every card for a category, including
     * the audits, payment vouchers and hiring that belong to no project — a company given
     * only the delivery types would have to file all of that under Others.
     */
    public function test_a_fresh_tenant_gets_the_pickable_types_and_the_two_generated_ones(): void
    {
        TimesheetCategory::seedFor($this->tenant);

        $this->assertSame([
            'Development', 'Maintenance', 'InHouse Project', 'Sales', 'Continuous Improvement (CI)',
            'Account and Finance', 'HR and Admin', 'Administration', 'Study & Research',
            'Marketing', 'Charity', 'Others', 'Public Holiday', 'On Leave',
        ], $this->names());
    }

    /**
     * The types the director costs against a job carry a project; the overhead ones stand
     * alone. `requires_project` is what the board reads to decide whether to ask for a
     * project at all, and what the project screen's tagging picker offers.
     */
    public function test_only_the_delivery_types_require_a_project(): void
    {
        TimesheetCategory::seedFor($this->tenant);

        $requiresProject = TimesheetCategory::withoutGlobalScope('tenant')
            ->where('tenant_id', $this->tenant->id)
            ->where('requires_project', true)
            ->pluck('name')
            ->sort()
            ->values()
            ->all();

        $this->assertSame([
            'Continuous Improvement (CI)', 'Development', 'InHouse Project', 'Maintenance', 'Sales',
        ], $requiresProject);
    }

    public function test_seeding_twice_adds_nothing_and_keeps_what_the_company_edited(): void
    {
        TimesheetCategory::seedFor($this->tenant);

        $sales = TimesheetCategory::withoutGlobalScope('tenant')
            ->where('tenant_id', $this->tenant->id)->where('name', 'Sales')->sole();
        $sales->update(['name_ms' => 'Jualan Korporat', 'is_active' => false]);

        TimesheetCategory::seedFor($this->tenant);

        $this->assertCount(count(TimesheetCategory::DEFAULTS), $this->names());
        $this->assertSame('Jualan Korporat', $sales->fresh()->name_ms);
        $this->assertFalse($sales->fresh()->is_active);
    }

    /** A tenant part-way through — some defaults present, some not — gets only what is missing. */
    public function test_a_partly_stocked_tenant_gets_only_the_missing_ones(): void
    {
        TimesheetCategory::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Development',
            'requires_project' => true, 'sort' => 0, 'is_active' => true,
        ]);

        TimesheetCategory::seedFor($this->tenant);

        $this->assertCount(count(TimesheetCategory::DEFAULTS), $this->names());
        $this->assertSame(1, TimesheetCategory::withoutGlobalScope('tenant')
            ->where('tenant_id', $this->tenant->id)->where('name', 'Development')->count());
    }
}
