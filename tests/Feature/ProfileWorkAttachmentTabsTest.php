<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProfileWorkAttachmentTabsTest extends TestCase
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

    public function test_hr_sees_work_and_attachment_tabs_with_edit_and_upload(): void
    {
        $this->login('hr');
        $e = $this->emp('Adibah', ['attendance_id' => 'ATT-5']);
        Asset::create(['tenant_id' => $this->tenant->id, 'employee_id' => $e->id, 'name' => 'Dell XPS', 'category' => 'laptop', 'status' => 'assigned', 'reference_no' => 'REF-3']);
        EmployeeDocument::create(['tenant_id' => $this->tenant->id, 'employee_id' => $e->id, 'title' => 'Offer letter', 'category' => 'Contract', 'file_path' => 'x/y.pdf', 'original_name' => 'y.pdf', 'mime' => 'application/pdf', 'size' => 10, 'uploaded_by_employee_id' => $e->id]);
        $this->get("/app/profile?emp={$e->id}")->assertOk()
            ->assertSee('data-tab="workinfo"', false)->assertSee('data-tab="attachment"', false)->assertDontSee('data-tab="assets"', false)
            ->assertSee('ATT-5')->assertSee('Dell XPS')->assertSee('REF-3')->assertSee("/app/employees/{$e->id}/work", false)
            ->assertSee('Offer letter')->assertSee('action="'.route('documents.store').'"', false);
    }

    public function test_employee_own_view_reads_work_and_can_upload_attachment(): void
    {
        $me = $this->login('employee', ['attendance_id' => 'ATT-9']);
        $this->get('/app/profile')->assertOk()
            ->assertSee('data-tab="workinfo"', false)->assertSee('ATT-9')->assertDontSee("/app/employees/{$me->id}/work", false)
            ->assertSee('data-tab="attachment"', false)->assertSee('action="'.route('documents.store').'"', false);
    }

    public function test_manager_sees_neither_tab_on_a_report(): void
    {
        $m = $this->login('manager');
        $e = $this->emp('Adibah', ['reports_to_id' => $m->id]);
        $this->get("/app/profile?emp={$e->id}")->assertOk()
            ->assertDontSee('data-tab="workinfo"', false)->assertDontSee('data-tab="attachment"', false)->assertDontSee('data-tab="assets"', false);
    }
}
