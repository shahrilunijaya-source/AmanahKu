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

    public function test_a_fresh_tenant_gets_the_five_pickable_types_and_the_two_generated_ones(): void
    {
        TimesheetCategory::seedFor($this->tenant);

        $this->assertSame([
            'Development', 'Maintenance', 'InHouse Project', 'Sales', 'Others',
            'Public Holiday', 'On Leave',
        ], $this->names());
    }

    /** The four the director costs project work against carry a project; the rest stand alone. */
    public function test_the_project_linkable_types_require_a_project(): void
    {
        TimesheetCategory::seedFor($this->tenant);

        $requiresProject = TimesheetCategory::withoutGlobalScope('tenant')
            ->where('tenant_id', $this->tenant->id)
            ->where('requires_project', true)
            ->pluck('name')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(collect(TimesheetCategory::PROJECT_LINKABLE)->sort()->values()->all(), $requiresProject);
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
