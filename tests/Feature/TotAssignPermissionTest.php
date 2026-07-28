<?php

namespace Tests\Feature;

use App\Support\Permissions;
use Tests\TestCase;

class TotAssignPermissionTest extends TestCase
{
    public function test_hr_and_management_hold_tot_assign_by_role(): void
    {
        $this->assertTrue(Permissions::roleHas('hr', 'tot.assign'));
        $this->assertTrue(Permissions::roleHas('management', 'tot.assign'));
        $this->assertTrue(Permissions::roleHas('director', 'tot.assign'));
    }

    public function test_manager_and_employee_do_not_hold_it_by_role(): void
    {
        $this->assertFalse(Permissions::roleHas('manager', 'tot.assign'));
        $this->assertFalse(Permissions::roleHas('employee', 'tot.assign'));
    }

    public function test_it_is_overridable_and_grouped_under_tot(): void
    {
        $this->assertContains('tot.assign', Permissions::overridable());
        $this->assertSame(['tot.assign'], Permissions::overridableGrouped()['tot'] ?? []);
    }
}
