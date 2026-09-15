<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeEducation;
use App\Models\Tenant;
use App\Support\ExperienceOptions;
use App\Support\StatutoryOptions;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ExperienceSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_tables_columns_relations_and_options(): void
    {
        foreach (['bank_holder_name', 'tax_resident', 'tax_category', 'employee_tax_status', 'child_relief_breakdown', 'epf_scheme', 'socso_category'] as $c) {
            $this->assertTrue(Schema::hasColumn('salary_structures', $c), $c);
        }
        foreach (['employee_work_histories', 'employee_educations', 'employee_certificates', 'employee_awards', 'employee_languages'] as $t) {
            $this->assertTrue(Schema::hasTable($t), $t);
        }

        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        app(CurrentTenant::class)->set($tenant);
        $e = Employee::create(['tenant_id' => $tenant->id, 'name' => 'A', 'status' => 'active', 'workload' => 'green']);
        $e->workHistories()->create(['tenant_id' => $tenant->id, 'company' => 'Old Co', 'joined_on' => '2019-01-01', 'salary_type' => 'monthly']);
        $e->educations()->create(['tenant_id' => $tenant->id, 'qualification_type' => 'bachelor', 'institute' => 'UM', 'from_year' => 2015, 'to_year' => 2019]);
        $e->certificates()->create(['tenant_id' => $tenant->id, 'name' => 'AWS']);
        $e->awards()->create(['tenant_id' => $tenant->id, 'title' => 'Dean List', 'year' => 2018]);
        $e->languages()->create(['tenant_id' => $tenant->id, 'language' => 'Malay', 'speaking' => 'native', 'reading' => 'native', 'writing' => 'fluent']);
        $this->assertSame('2019-01-01', $e->workHistories()->first()->joined_on->toDateString());
        $this->assertSame(1, $e->languages()->count());

        $s = $e->salaryStructure()->create(['tenant_id' => $tenant->id, 'basic_salary' => 3000, 'tax_resident' => false, 'child_relief_breakdown' => ['under_18' => ['100' => 1, '50' => 0]]]);
        $this->assertFalse($s->fresh()->tax_resident);
        $this->assertSame(1, $s->fresh()->child_relief_breakdown['under_18']['100']);

        $this->assertContains('Maybank', StatutoryOptions::BANKS);
        $this->assertSame(['work', 'education', 'certificate', 'award', 'language'], array_keys(ExperienceOptions::TYPES));
        $this->assertSame(EmployeeEducation::class, ExperienceOptions::TYPES['education'][0]);
    }
}
