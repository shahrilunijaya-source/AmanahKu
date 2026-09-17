<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeAward;
use App\Models\EmployeeCertificate;
use App\Models\EmployeeDocument;
use App\Models\EmployeeEducation;
use App\Models\EmployeeLanguage;
use App\Models\EmployeeWorkHistory;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ExperienceRecordTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        app(CurrentTenant::class)->set($this->tenant);
    }

    private function login(string $role, array $attrs = []): Employee
    {
        $user = User::create(['name' => ucfirst($role), 'email' => $role.'@example.com', 'password' => Hash::make('password')]);
        $user->tenants()->attach($this->tenant->id, ['role' => $role]);
        $employee = Employee::create(array_merge([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'name' => ucfirst($role), 'status' => 'active', 'workload' => 'green', 'joined_at' => '2025-01-06',
        ], $attrs));
        $this->actingAs($user)->withSession(['current_tenant' => $this->tenant->id]);

        return $employee;
    }

    private function emp(string $name, array $attrs = []): Employee
    {
        return Employee::create(array_merge([
            'tenant_id' => $this->tenant->id, 'name' => $name, 'status' => 'active', 'workload' => 'green', 'joined_at' => '2026-01-05',
        ], $attrs));
    }

    public function test_hr_adds_edits_and_deletes_each_type(): void
    {
        $this->login('hr');
        $e = $this->emp('Adibah');
        $cases = [
            'work' => [EmployeeWorkHistory::class, ['company' => 'Old Co', 'joined_on' => '2019-01-01', 'resigned_on' => '2021-06-30', 'last_drawn_salary' => '4500.50', 'salary_type' => 'monthly'], 'company', 'New Co'],
            'education' => [EmployeeEducation::class, ['qualification_type' => 'bachelor', 'institute' => 'UM', 'from_year' => 2015, 'to_year' => 2019, 'honours' => 'first', 'cgpa' => '3.75'], 'institute', 'UKM'],
            'certificate' => [EmployeeCertificate::class, ['name' => 'AWS SAA', 'awarded_on' => '2022-02-02'], 'name', 'AWS SAP'],
            'award' => [EmployeeAward::class, ['title' => 'Dean List', 'year' => 2018], 'title', 'Gold'],
            'language' => [EmployeeLanguage::class, ['language' => 'Malay', 'speaking' => 'native', 'reading' => 'native', 'writing' => 'fluent'], 'language', 'English'],
        ];
        foreach ($cases as $type => [$model, $payload, $field, $newValue]) {
            $this->post("/app/employees/{$e->id}/experience/{$type}", $payload)->assertRedirect("/app/profile?emp={$e->id}&tab=experience");
            $row = $model::firstOrFail();
            $this->post("/app/experience/{$type}/{$row->id}", array_merge($payload, [$field => $newValue]))->assertRedirect();
            $this->assertSame($newValue, $row->fresh()->{$field});
            $this->post("/app/experience/{$type}/{$row->id}/delete")->assertRedirect();
            $this->assertSame(0, $model::count(), $type);
        }
    }

    public function test_employee_edits_own_only(): void
    {
        $me = $this->login('employee');
        $other = $this->emp('Adibah');
        $this->post("/app/employees/{$me->id}/experience/language", ['language' => 'Malay', 'speaking' => 'native'])->assertRedirect();
        $this->post("/app/employees/{$other->id}/experience/language", ['language' => 'Malay'])->assertForbidden();
        $theirs = $other->languages()->create(['tenant_id' => $this->tenant->id, 'language' => 'Tamil']);
        $this->post("/app/experience/language/{$theirs->id}", ['language' => 'X'])->assertForbidden();
        $this->post("/app/experience/language/{$theirs->id}/delete")->assertForbidden();
    }

    public function test_manager_cannot_edit_report(): void
    {
        $m = $this->login('manager');
        $e = $this->emp('Adibah', ['reports_to_id' => $m->id]);
        $this->post("/app/employees/{$e->id}/experience/award", ['title' => 'X'])->assertForbidden();
    }

    public function test_invalid_type_and_options_rejected(): void
    {
        $this->login('hr');
        $e = $this->emp('Adibah');
        $this->post("/app/employees/{$e->id}/experience/hobby", ['title' => 'X'])->assertNotFound();
        $this->post("/app/employees/{$e->id}/experience/education", ['qualification_type' => 'phd', 'honours' => 'gold', 'to_year' => 1800])
            ->assertSessionHasErrors(['qualification_type', 'honours', 'to_year']);
        $this->post("/app/employees/{$e->id}/experience/work", ['company' => 'A', 'joined_on' => '2020-01-01', 'resigned_on' => '2019-01-01'])
            ->assertSessionHasErrors('resigned_on');
    }

    public function test_attachment_must_belong_to_same_employee(): void
    {
        $this->login('hr');
        $e = $this->emp('Adibah');
        $other = $this->emp('Bob');
        $doc = EmployeeDocument::create(['tenant_id' => $this->tenant->id, 'employee_id' => $other->id, 'title' => 'Cert', 'category' => 'Other', 'file_path' => 'x', 'original_name' => 'x.pdf', 'mime' => 'application/pdf', 'size' => 1]);
        $this->post("/app/employees/{$e->id}/experience/certificate", ['name' => 'X', 'document_id' => $doc->id])->assertSessionHasErrors('document_id');
        $mine = EmployeeDocument::create(['tenant_id' => $this->tenant->id, 'employee_id' => $e->id, 'title' => 'Mine', 'category' => 'Other', 'file_path' => 'y', 'original_name' => 'y.pdf', 'mime' => 'application/pdf', 'size' => 1]);
        $this->post("/app/employees/{$e->id}/experience/certificate", ['name' => 'X', 'document_id' => $mine->id])->assertRedirect();
        $this->assertSame($mine->id, EmployeeCertificate::firstOrFail()->document_id);
    }

    public function test_other_tenant_not_found(): void
    {
        $this->login('hr');
        $other = Tenant::create(['slug' => 'zed', 'name' => 'Zed', 'initials' => 'ZD']);
        $s = Employee::withoutGlobalScopes()->create(['tenant_id' => $other->id, 'name' => 'S', 'status' => 'active', 'workload' => 'green']);
        $this->post("/app/employees/{$s->id}/experience/award", ['title' => 'X'])->assertNotFound();
        $row = EmployeeLanguage::withoutGlobalScopes()->create(['tenant_id' => $other->id, 'employee_id' => $s->id, 'language' => 'X']);
        $this->post("/app/experience/language/{$row->id}", ['language' => 'Y'])->assertNotFound();
    }
}
