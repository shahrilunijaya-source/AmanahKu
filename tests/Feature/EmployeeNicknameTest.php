<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Staff refer to each other by nickname ("Hakime", "Kak Lin") while `name` carries the full
 * legal name. Two ways in: HR sets it on the directory record, and a person sets their own
 * on the first-login wizard, which is the self-service path the profile screen links to.
 */
class EmployeeNicknameTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $hr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $this->hr = User::create(['name' => 'Boss', 'email' => 'boss@example.com', 'password' => Hash::make('password')]);
        $this->hr->tenants()->attach($this->tenant->id, ['role' => 'hr']);
    }

    private function actingHr(): self
    {
        $this->actingAs($this->hr)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    public function test_hr_sets_a_nickname_through_the_employee_update_route(): void
    {
        $employee = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Mohd Hakime Bin Md Nasri',
            'status' => 'active', 'workload' => 'green',
        ]);

        $this->actingHr()->post('/app/employees/'.$employee->id, [
            'name' => 'Mohd Hakime Bin Md Nasri',
            'nickname' => 'Hakime',
            'status' => 'active',
        ])->assertRedirect();

        $this->assertSame('Hakime', $employee->fresh()->nickname);
    }

    public function test_hr_sets_a_nickname_when_adding_an_employee(): void
    {
        $this->actingHr()->post('/app/employees', [
            'name' => 'Nur Aizatul Aliya', 'nickname' => 'Aizat', 'status' => 'probation',
        ])->assertRedirect();

        $this->assertSame('Aizat', Employee::where('name', 'Nur Aizatul Aliya')->firstOrFail()->nickname);
    }

    public function test_a_person_sets_their_own_nickname_on_the_profile_wizard(): void
    {
        $user = User::create(['name' => 'Kussairi', 'email' => 'kus@example.com', 'password' => Hash::make('password')]);
        $user->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $employee = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'name' => 'Mohd Kussairi Bin Ahmad', 'status' => 'active', 'workload' => 'green',
        ]);

        $this->actingAs($user)->withSession(['current_tenant' => $this->tenant->id])
            ->post('/app/welcome/personal', [
                'nickname' => 'Kussairi',
                'nric' => '900101015555',
                'date_of_birth' => '1990-01-01',
                'gender' => 'male',
                'marital_status' => 'married',
                'phone' => '0123456789',
                'address' => '1 Jalan Satu, Kuala Lumpur',
                'emergency_contact_name' => 'Siti',
                'emergency_contact_phone' => '0198765432',
            ])->assertRedirect();

        $this->assertSame('Kussairi', $employee->fresh()->nickname);
    }

    public function test_a_nickname_over_sixty_characters_is_rejected(): void
    {
        $employee = Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Long Name',
            'status' => 'active', 'workload' => 'green',
        ]);

        $this->actingHr()->post('/app/employees/'.$employee->id, [
            'name' => 'Long Name', 'nickname' => str_repeat('a', 61), 'status' => 'active',
        ])->assertSessionHasErrors('nickname');

        $this->assertNull($employee->fresh()->nickname);
    }

    /** One format everywhere: the legal name first, then the short name in quotes. */
    public function test_display_name_appends_the_nickname_in_quotes(): void
    {
        $employee = new Employee(['name' => 'Mohd Hakime Bin Md Nasri', 'nickname' => 'Hakime']);

        $this->assertSame('Mohd Hakime Bin Md Nasri "Hakime"', $employee->display_name);
    }

    public function test_display_name_falls_back_to_the_full_name_with_no_nickname(): void
    {
        $employee = new Employee(['name' => 'Mohd Hakime Bin Md Nasri']);

        $this->assertSame('Mohd Hakime Bin Md Nasri', $employee->display_name);
    }
}
