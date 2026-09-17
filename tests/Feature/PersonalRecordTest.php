<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PersonalRecordTest extends TestCase
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

    public function test_hr_updates_personal_and_identity(): void
    {
        $this->login('hr');
        $e = $this->emp('Adibah');
        $this->post("/app/employees/{$e->id}/personal", [
            'first_name' => 'Adibah', 'last_name' => 'Zainal', 'religion' => 'Islam', 'race' => 'Malay', 'nationality' => 'Malaysian',
            'blood_type' => 'O+', 'personal_email' => 'adibah@gmail.com', 'address' => '1 Jalan A', 'address_2' => 'Taman B', 'city' => 'Shah Alam',
            'state' => 'Selangor', 'postcode' => '40000', 'country' => 'Malaysia', 'emergency_contact_name' => 'Mak', 'emergency_contact_phone' => '0123', 'emergency_contact_relationship' => 'Mother',
            'nric' => '900101-10-1234', 'passport_no' => 'A123', 'passport_expiry' => '2030-01-01', 'gender' => 'female', 'marital_status' => 'married',
        ])->assertRedirect("/app/profile?emp={$e->id}&tab=personal");
        $e->refresh();
        $this->assertSame('Zainal', $e->last_name);
        $this->assertSame('900101-10-1234', $e->nric);
        $this->assertSame('2030-01-01', $e->passport_expiry->toDateString());
        $this->assertSame('Shah Alam', $e->city);
    }

    public function test_employee_edits_own_record_but_identity_is_ignored(): void
    {
        $me = $this->login('employee', ['nric' => 'OLD']);
        $this->post("/app/employees/{$me->id}/personal", ['city' => 'Ipoh', 'nric' => 'NEW', 'passport_no' => 'P1'])->assertRedirect();
        $me->refresh();
        $this->assertSame('Ipoh', $me->city);
        $this->assertSame('OLD', $me->nric);
        $this->assertNull($me->passport_no);
    }

    public function test_employee_cannot_edit_someone_else(): void
    {
        $this->login('employee');
        $e = $this->emp('Adibah');
        $this->post("/app/employees/{$e->id}/personal", ['city' => 'X'])->assertForbidden();
    }

    public function test_manager_cannot_edit_a_report(): void
    {
        $m = $this->login('manager');
        $e = $this->emp('Adibah', ['reports_to_id' => $m->id]);
        $this->post("/app/employees/{$e->id}/personal", ['city' => 'X'])->assertForbidden();
    }

    public function test_invalid_option_is_rejected(): void
    {
        $this->login('hr');
        $e = $this->emp('Adibah');
        $this->post("/app/employees/{$e->id}/personal", ['blood_type' => 'Z', 'marital_status' => 'complicated'])
            ->assertSessionHasErrors(['blood_type', 'marital_status']);
    }

    public function test_other_tenant_is_not_found(): void
    {
        $this->login('hr');
        $other = Tenant::create(['slug' => 'zed', 'name' => 'Zed', 'initials' => 'ZD']);
        $s = Employee::withoutGlobalScopes()->create(['tenant_id' => $other->id, 'name' => 'S', 'status' => 'active', 'workload' => 'green']);
        $this->post("/app/employees/{$s->id}/personal", ['city' => 'X'])->assertNotFound();
    }
}
