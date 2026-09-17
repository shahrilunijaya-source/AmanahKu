<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeFamilyMember;
use App\Models\Tenant;
use App\Support\PersonalOptions;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PersonalSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_columns_table_and_options_exist(): void
    {
        foreach (['first_name', 'last_name', 'full_name_ic', 'religion', 'race', 'nationality', 'blood_type', 'personal_email', 'address_2', 'city', 'state', 'postcode', 'country', 'emergency_contact_relationship', 'passport_no', 'passport_expiry', 'permit_no', 'permit_expiry'] as $c) {
            $this->assertTrue(Schema::hasColumn('employees', $c), $c);
        }
        $this->assertTrue(Schema::hasTable('employee_family_members'));

        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        app(CurrentTenant::class)->set($tenant);
        $e = Employee::create(['tenant_id' => $tenant->id, 'name' => 'A', 'status' => 'active', 'workload' => 'green']);
        $this->assertSame('Malaysia', $e->fresh()->country);

        $m = $e->familyMembers()->create(['tenant_id' => $tenant->id, 'relation' => 'father', 'name' => 'Pak', 'date_of_birth' => '1960-02-03']);
        $this->assertInstanceOf(EmployeeFamilyMember::class, $m);
        $this->assertSame('1960-02-03', $m->fresh()->date_of_birth->toDateString());

        $this->assertContains('Malaysian', PersonalOptions::NATIONALITIES);
        $this->assertContains('Malay', PersonalOptions::RACES);
        $this->assertSame(['father', 'mother', 'spouse', 'child', 'dependent'], PersonalOptions::RELATIONS);
        $this->assertSame(['student', 'unemployed', 'working'], PersonalOptions::OCCUPATIONS);
    }
}
