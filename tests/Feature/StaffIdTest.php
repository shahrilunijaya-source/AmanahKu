<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Staff numbers are handed out by the app in Worksy's format (UR00023). */
class StaffIdTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
    }

    private function employee(?string $staffId, array $extra = []): Employee
    {
        return Employee::create(['tenant_id' => $this->tenant->id, 'name' => 'Person', 'status' => 'active', 'workload' => 'green', 'staff_id' => $staffId] + $extra);
    }

    public function test_first_employee_uses_the_company_initials(): void
    {
        $this->assertSame('AC00001', $this->employee(null)->staff_id);
    }

    public function test_takes_the_lowest_free_number_and_the_existing_prefix(): void
    {
        $this->employee('UR00001');
        $this->employee('UR00002');
        $this->employee('UR00055'); // set aside for Admin HR
        $this->employee('UR08888', ['archived_at' => now()]); // director, archived still counts

        $this->assertSame('UR00003', $this->employee(null)->staff_id);
        $this->assertSame('UR00004', $this->employee('')->staff_id);
    }

    public function test_steps_over_a_number_set_aside_by_hand(): void
    {
        foreach (range(1, 54) as $n) {
            $this->employee('UR'.str_pad((string) $n, 5, '0', STR_PAD_LEFT));
        }
        $this->employee('UR00055');

        $this->assertSame('UR00056', $this->employee(null)->staff_id);
    }

    public function test_a_number_typed_by_hr_is_kept(): void
    {
        $this->assertSame('UR09999', $this->employee('UR09999')->staff_id);
    }

    public function test_migration_renumbers_everyone_by_join_date(): void
    {
        $director = $this->employee('UR-8888', ['joined_at' => '2002-07-04']);
        $admin = $this->employee('UR-0055', ['joined_at' => '2025-07-01']);
        $old = $this->employee('UR-0001', ['joined_at' => '2020-08-03']);
        $blank = $this->employee('X', ['joined_at' => '2026-04-01']);
        $sameDayLater = $this->employee('Y', ['joined_at' => '2020-08-03']);
        DB::table('employees')->where('id', $blank->id)->update(['staff_id' => null]);

        (include database_path('migrations/2026_10_02_100011_normalise_employee_staff_ids.php'))->up();

        $this->assertSame('UR00001', $director->fresh()->staff_id);
        $this->assertSame('UR00002', $old->fresh()->staff_id);
        $this->assertSame('UR00003', $sameDayLater->fresh()->staff_id);
        $this->assertSame('UR00004', $admin->fresh()->staff_id);
        $this->assertSame('UR00005', $blank->fresh()->staff_id);
        $this->assertSame('UR00006', $this->employee(null)->staff_id);
    }
}
