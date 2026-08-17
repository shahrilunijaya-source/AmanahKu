<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Models\SubPillar;
use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A sub-pillar is a kind of work (Management / Meeting / Technical), shared by
 * every project in the tenant — not a part of one project. Unijaya's 24 project
 * records all carried the identical three before this change.
 */
class SubPillarTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
    }

    public function test_a_sub_pillar_belongs_to_a_tenant_and_not_to_a_project(): void
    {
        $sub = SubPillar::create(['tenant_id' => $this->tenant->id, 'name' => 'Technical']);

        $this->assertTrue($sub->is_active);
        $this->assertSame(0, $sub->sort);
        $this->assertFalse(array_key_exists('project_id', $sub->getAttributes()));
    }

    public function test_the_same_name_cannot_be_added_twice_in_one_tenant(): void
    {
        SubPillar::create(['tenant_id' => $this->tenant->id, 'name' => 'Meeting']);

        $this->expectException(QueryException::class);
        SubPillar::create(['tenant_id' => $this->tenant->id, 'name' => 'Meeting']);
    }

    public function test_two_projects_need_only_one_copy_of_a_sub_pillar(): void
    {
        Project::create(['tenant_id' => $this->tenant->id, 'name' => 'JKDM: MyStods']);
        Project::create(['tenant_id' => $this->tenant->id, 'name' => 'KKM: NSFIRM']);
        SubPillar::create(['tenant_id' => $this->tenant->id, 'name' => 'Management']);

        // The old shape stored one row per project. One row now serves both.
        $this->assertSame(2, Project::where('tenant_id', $this->tenant->id)->count());
        $this->assertSame(1, SubPillar::where('tenant_id', $this->tenant->id)->count());
    }
}
