<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Support\PersonName;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Names are stored with every word capitalised, on staff records and logins alike. */
class PersonNameTest extends TestCase
{
    use RefreshDatabase;

    public function test_format(): void
    {
        $this->assertSame('Maryam Solihah Binti Abdul Karim', PersonName::format('MARYAM SOLIHAH BINTI ABDUL KARIM'));
        $this->assertSame('Ahmad Bin Ali', PersonName::format('  ahmad   bin ali '));
        $this->assertSame('DzulHazly Bin Zainal Abidin', PersonName::format('DzulHazly bin Zainal Abidin'));
        $this->assertSame("Nur'ain Siti-Aishah", PersonName::format("NUR'AIN siti-aishah"));
        $this->assertSame('Ravi A/L Kumar', PersonName::format('RAVI a/l KUMAR'));
    }

    public function test_employee_name_is_formatted_and_copied_to_the_login(): void
    {
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $user = User::create(['name' => 'SITI AMINAH', 'email' => 's@example.com', 'password' => 'password']);
        $this->assertSame('Siti Aminah', $user->name);

        $employee = Employee::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'name' => 'SITI AMINAH', 'status' => 'active', 'workload' => 'green']);
        $this->assertSame('Siti Aminah', $employee->name);

        $employee->update(['name' => 'siti nur aminah']);
        $this->assertSame('Siti Nur Aminah', $employee->fresh()->name);
        $this->assertSame('Siti Nur Aminah', $user->fresh()->name);
    }

    public function test_migration_fixes_names_already_saved(): void
    {
        $user = User::create(['name' => 'x', 'email' => 'm@example.com', 'password' => 'password']);
        DB::table('users')->where('id', $user->id)->update(['name' => 'MARYAM SOLIHAH']);

        (include database_path('migrations/2026_10_02_100012_standardise_person_names.php'))->up();

        $this->assertSame('Maryam Solihah', $user->fresh()->name);
    }
}
