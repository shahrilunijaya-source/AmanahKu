<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\EmployeeFamilyMember;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class FamilyMemberTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        app(CurrentTenant::class)->set($this->tenant);
    }

    /** Logs in as $role and returns that user's own employee row. */
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

    public function test_hr_adds_edits_and_deletes_a_family_member(): void
    {
        $this->login('hr');
        $e = $this->emp('Adibah');
        $this->post("/app/employees/{$e->id}/family", ['relation' => 'spouse', 'name' => 'Ali', 'marriage_date' => '2015-05-05', 'employer_name' => 'Petronas', 'occupation' => 'working'])
            ->assertRedirect("/app/profile?emp={$e->id}&tab=family");
        $m = EmployeeFamilyMember::firstOrFail();
        $this->assertSame('Petronas', $m->employer_name);

        $this->post("/app/family/{$m->id}", ['relation' => 'spouse', 'name' => 'Ali B', 'deceased' => '1'])->assertRedirect();
        $this->assertSame('Ali B', $m->fresh()->name);
        $this->assertTrue($m->fresh()->deceased);

        $this->post("/app/family/{$m->id}/delete")->assertRedirect();
        $this->assertSame(0, EmployeeFamilyMember::count());
    }

    public function test_employee_manages_own_family_only(): void
    {
        $me = $this->login('employee');
        $other = $this->emp('Adibah');
        $this->post("/app/employees/{$me->id}/family", ['relation' => 'child', 'name' => 'Kid', 'education' => 'Primary'])->assertRedirect();
        $this->post("/app/employees/{$other->id}/family", ['relation' => 'child', 'name' => 'Kid'])->assertForbidden();

        $theirs = $other->familyMembers()->create(['tenant_id' => $this->tenant->id, 'relation' => 'father', 'name' => 'X']);
        $this->post("/app/family/{$theirs->id}", ['relation' => 'father', 'name' => 'Y'])->assertForbidden();
        $this->post("/app/family/{$theirs->id}/delete")->assertForbidden();
    }

    public function test_only_one_father_and_one_mother(): void
    {
        $this->login('hr');
        $e = $this->emp('Adibah');
        $this->post("/app/employees/{$e->id}/family", ['relation' => 'father', 'name' => 'A'])->assertRedirect();
        $this->post("/app/employees/{$e->id}/family", ['relation' => 'father', 'name' => 'B'])->assertSessionHasErrors('relation');
        $this->assertSame(1, EmployeeFamilyMember::count());
    }

    public function test_invalid_relation_rejected(): void
    {
        $this->login('hr');
        $e = $this->emp('Adibah');
        $this->post("/app/employees/{$e->id}/family", ['relation' => 'cousin', 'name' => 'A'])->assertSessionHasErrors('relation');
    }

    public function test_other_tenant_member_not_found(): void
    {
        $this->login('hr');
        $other = Tenant::create(['slug' => 'zed', 'name' => 'Zed', 'initials' => 'ZD']);
        $s = Employee::withoutGlobalScopes()->create(['tenant_id' => $other->id, 'name' => 'S', 'status' => 'active', 'workload' => 'green']);
        $m = EmployeeFamilyMember::withoutGlobalScopes()->create(['tenant_id' => $other->id, 'employee_id' => $s->id, 'relation' => 'father', 'name' => 'X']);
        $this->post("/app/family/{$m->id}", ['relation' => 'father', 'name' => 'Y'])->assertNotFound();
        $this->post("/app/employees/{$s->id}/family", ['relation' => 'father', 'name' => 'Y'])->assertNotFound();
    }
}
