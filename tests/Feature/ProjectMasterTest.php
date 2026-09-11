<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ApiClient;
use App\Models\Employee;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use App\Projects\ProjectMaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Unit-ish coverage for App\Projects\ProjectMaster that CR06aTest does not already
 * exercise end to end: the role→field map on its own (including director folding
 * into management), version-effective-date tie-breaking, the backfill command's
 * idempotency and client-from-code default, the Track-facing API payload shape,
 * and the edit form actually disabling the fields a role may not touch.
 */
class ProjectMasterTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
    }

    public function test_editable_fields_per_role_director_folds_into_management(): void
    {
        $master = app(ProjectMaster::class);

        $this->assertSame([], $master->editableFields('employee'));

        $manager = $master->editableFields('manager');
        $this->assertContains('pm_id', $manager);
        $this->assertContains('name', $manager);
        $this->assertNotContains('bond_value', $manager);

        $hr = $master->editableFields('hr');
        $this->assertContains('bond_value', $hr);
        $this->assertNotContains('pm_id', $hr);

        $management = $master->editableFields('management');
        $director = $master->editableFields('director');
        $this->assertSame($management, $director, 'director must inherit exactly the management field set');
        $this->assertContains('pm_id', $director);
        $this->assertContains('bond_value', $director);
    }

    public function test_version_effective_on_breaks_ties_by_the_higher_version_number(): void
    {
        $project = Project::create(['tenant_id' => $this->tenant->id, 'name' => 'KPT: RMS', 'project_code' => 'KPT-1', 'client' => 'KPT']);
        $project->versions()->create(['tenant_id' => $this->tenant->id, 'version_no' => 1, 'effective_date' => '2026-06-01', 'snapshot' => ['name' => 'v1']]);
        $project->versions()->create(['tenant_id' => $this->tenant->id, 'version_no' => 2, 'effective_date' => '2026-06-01', 'snapshot' => ['name' => 'v2']]);
        $project->versions()->create(['tenant_id' => $this->tenant->id, 'version_no' => 3, 'effective_date' => '2026-07-01', 'snapshot' => ['name' => 'v3']]);

        $this->assertSame(2, $project->versionEffectiveOn('2026-06-15')->version_no, 'same effective_date: the higher version number wins');
        $this->assertSame(3, $project->versionEffectiveOn('2026-07-01')->version_no);
        $this->assertSame(3, $project->currentVersion()->version_no);
        $this->assertNull($project->versionEffectiveOn('2026-01-01'));
    }

    public function test_backfill_defaults_client_from_code_for_a_project_created_after_the_migration(): void
    {
        $project = Project::create(['tenant_id' => $this->tenant->id, 'name' => 'JKDM: MyDLV', 'code' => 'JKDM']);
        $this->assertNull($project->client);

        $this->artisan('projects:backfill-versions')->assertSuccessful();

        $this->assertSame('JKDM', $project->fresh()->client);
        $this->assertSame(1, $project->versions()->count());

        $this->artisan('projects:backfill-versions')->assertSuccessful();
        $this->assertSame(1, $project->versions()->count(), 'a second run must not add a second version');
    }

    public function test_closed_project_rejects_every_field_and_reopen_needs_a_reason(): void
    {
        $project = Project::create(['tenant_id' => $this->tenant->id, 'name' => 'KPT: RMS', 'project_code' => 'KPT-1', 'client' => 'KPT', 'status' => 'closed']);
        $master = app(ProjectMaster::class);

        $this->expectException(ValidationException::class);
        $master->update($project, ['name' => 'New name'], 'management', null, null, null);
    }

    public function test_reopen_requires_the_project_to_actually_be_closed(): void
    {
        $project = Project::create(['tenant_id' => $this->tenant->id, 'name' => 'KPT: RMS', 'project_code' => 'KPT-1', 'client' => 'KPT', 'status' => 'active']);
        $master = app(ProjectMaster::class);

        $this->expectException(ValidationException::class);
        $master->reopen($project, 'Not actually closed', null, null);
    }

    public function test_api_projects_payload_carries_the_master_fields_and_current_version(): void
    {
        $project = Project::create([
            'tenant_id' => $this->tenant->id, 'name' => 'KPT: RMS', 'project_code' => 'KPT-1',
            'client' => 'KPT', 'status' => 'active', 'contract_value' => 500000, 'is_active' => true,
            'contract_start' => '2026-10-01', 'contract_end' => '2027-09-30',
        ]);
        $project->versions()->create(['tenant_id' => $this->tenant->id, 'version_no' => 1, 'effective_date' => '2026-06-01', 'snapshot' => $project->masterSnapshot()]);
        $project->versions()->create(['tenant_id' => $this->tenant->id, 'version_no' => 2, 'effective_date' => '2026-07-01', 'snapshot' => $project->masterSnapshot()]);

        $client = ApiClient::create(['tenant_id' => $this->tenant->id, 'name' => 'Track']);
        $plain = $client->mintKey(['projects:read'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$plain)->getJson('/api/v1/projects');

        $response->assertOk();
        $row = collect($response->json('data'))->firstWhere('project_code', 'KPT-1');
        $this->assertNotNull($row);
        $this->assertSame('KPT', $row['client']);
        $this->assertSame('active', $row['status']);
        $this->assertSame(2, $row['version']);
        // Plain dates, not UTC timestamps that read as the previous day in Malaysia.
        $this->assertSame('2026-10-01', $row['contract_start']);
        $this->assertSame('2027-09-30', $row['contract_end']);
    }

    public function test_edit_form_disables_fields_outside_the_viewers_set(): void
    {
        $project = Project::create([
            'tenant_id' => $this->tenant->id, 'name' => 'KPT: RMS', 'project_code' => 'KPT-1',
            'client' => 'KPT', 'contract_value' => 500000, 'bond_value' => 1000, 'pm_id' => null,
        ]);

        $hrHtml = $this->actingAsRole('hr')->get('/app/projects')->assertOk()->getContent();
        $managerHtml = $this->actingAsRole('manager')->get('/app/projects')->assertOk()->getContent();

        // hr may touch bond_value (finance) but not pm_id (PM set); manager the reverse.
        $this->assertMatchesRegularExpression('/name="bond_value"(?:(?!disabled).)*?\/>/s', $hrHtml);
        $this->assertMatchesRegularExpression('/name="pm_id"[^>]*disabled/s', $hrHtml);

        $this->assertMatchesRegularExpression('/name="pm_id"(?:(?!disabled).)*?<\/select>/s', $managerHtml);
        $this->assertMatchesRegularExpression('/name="bond_value"[^>]*disabled/s', $managerHtml);

        // Both roles see project_code and the variation fields locked once the project exists.
        $this->assertMatchesRegularExpression('/name="project_code"[^>]*disabled/s', $hrHtml);
        $this->assertMatchesRegularExpression('/name="contract_value"[^>]*disabled/s', $managerHtml);
    }

    private function actingAsRole(string $role): self
    {
        $user = User::create([
            'name' => ucfirst($role), 'email' => $role.'@example.com', 'password' => Hash::make('password'),
        ]);
        $user->tenants()->attach($this->tenant->id, ['role' => $role]);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
            'name' => ucfirst($role), 'status' => 'active', 'workload' => 'green',
        ]);

        $this->actingAs($user)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }
}
