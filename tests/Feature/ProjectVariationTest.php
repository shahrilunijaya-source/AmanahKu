<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ApiClient;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Validation edges and the Track `as_of` read path for CR-06b (App\Projects\
 * ProjectVariations) that tests/Acceptance/CR06bTest.php does not already cover end
 * to end: field-level validation messages, a sent-but-unchanged field, the redirect
 * (non-JSON) response shape, and as_of across more than one approved variation.
 */
class ProjectVariationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
    }

    /** Version 1 is written directly (not through ProjectMaster::create()), effective
     *  well before any as_of date these tests use, so a period read has something to find. */
    private function project(array $overrides = []): Project
    {
        $project = Project::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'project_code' => 'KPT-RMS-2026-01',
            'name' => 'KPT: RMS',
            'client' => 'Kementerian Pengangkutan',
            'status' => 'active',
            'contract_value' => 1250000.00,
            'contract_start' => '2026-08-01',
            'contract_end' => '2027-07-31',
        ], $overrides));

        $project->versions()->create([
            'tenant_id' => $this->tenant->id,
            'version_no' => 1,
            'effective_date' => '2026-06-01',
            'snapshot' => $project->masterSnapshot(),
        ]);

        return $project;
    }

    private function actingAsRole(string $role): self
    {
        $user = User::firstWhere('email', $role.'@example.com');

        if (! $user) {
            $user = User::create([
                'name' => ucfirst($role), 'email' => $role.'@example.com', 'password' => Hash::make('password'),
            ]);
            $user->tenants()->attach($this->tenant->id, ['role' => $role]);
            Employee::create([
                'tenant_id' => $this->tenant->id, 'user_id' => $user->id,
                'name' => ucfirst($role), 'status' => 'active', 'workload' => 'green',
            ]);
        }

        $this->actingAs($user)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    private function raise(Project $project, array $overrides = []): TestResponse
    {
        return $this->postJson(route('projects.variations.store', $project), array_merge([
            'vo_no' => 'VO-01',
            'variation_date' => '2026-10-01',
            'reason' => 'Additional scope',
            'contract_value' => 1500000,
        ], $overrides));
    }

    public function test_vo_no_variation_date_and_reason_are_required(): void
    {
        $project = $this->project();
        $this->actingAsRole('hr');

        $this->raise($project, ['vo_no' => str_repeat('A', 41)])->assertStatus(422);
        $this->raise($project, ['variation_date' => 'not-a-date'])->assertStatus(422);
        $this->raise($project, ['reason' => str_repeat('A', 501)])->assertStatus(422);
    }

    public function test_a_field_sent_but_identical_to_the_current_value_does_not_count_as_a_change(): void
    {
        $project = $this->project();
        $this->actingAsRole('hr');

        // Same contract_value as the project already carries: presence check passes,
        // the diff finds nothing, so the raise is still refused.
        $this->raise($project, ['contract_value' => '1250000.00'])->assertStatus(422);
    }

    public function test_attachment_must_be_one_of_the_allowed_types(): void
    {
        Storage::fake('local');
        $project = $this->project();
        $this->actingAsRole('management');

        $this->withHeader('Accept', 'application/json')->post(route('projects.variations.store', $project), [
            'vo_no' => 'VO-01', 'variation_date' => '2026-10-01', 'reason' => 'Scope',
            'contract_value' => 1500000,
            'attachment' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
        ])->assertStatus(422);
    }

    public function test_a_new_contract_start_in_the_same_vo_moves_the_end_date_floor(): void
    {
        $project = $this->project(); // contract_start 2026-08-01
        $this->actingAsRole('hr');

        // 2026-09-01 is before the OLD start's successor but after the NEW start
        // carried in the same VO (2026-08-15), so this must be accepted.
        $this->raise($project, [
            'contract_start' => '2026-08-15',
            'contract_end' => '2026-09-01',
        ])->assertStatus(201);
    }

    public function test_non_json_request_gets_a_redirect_back(): void
    {
        $project = $this->project();
        $this->actingAsRole('hr');

        $this->post(route('projects.variations.store', $project), [
            'vo_no' => 'VO-01', 'variation_date' => '2026-10-01', 'reason' => 'Scope',
            'contract_value' => 1500000,
        ])->assertRedirect();

        $variationId = $project->variations()->sole()->id;
        $this->actingAsRole('management');
        $this->post(route('projects.variations.approve', [$project, $variationId]))->assertRedirect();
    }

    public function test_a_plain_form_validation_failure_surfaces_as_a_toast_on_the_register(): void
    {
        $project = $this->project();
        $this->actingAsRole('hr');
        $payload = [
            'vo_no' => 'VO-01', 'variation_date' => '2026-10-01', 'reason' => 'Scope',
            'contract_value' => 1500000,
        ];
        $this->post(route('projects.variations.store', $project), $payload)->assertRedirect();

        $this->from(route('app.screen', 'projects'))
            ->followingRedirects()
            ->post(route('projects.variations.store', $project), $payload)
            ->assertOk()
            ->assertSee('The VO number has already been taken.');
    }

    public function test_approval_writes_exactly_one_audit_row_per_changed_field_with_the_vo_reason(): void
    {
        $project = $this->project();
        $this->actingAsRole('hr');
        $this->postJson(route('projects.variations.store', $project), [
            'vo_no' => 'VO-01', 'variation_date' => '2026-10-01', 'reason' => 'Scope',
            'contract_value' => 1500000, 'client' => 'New client',
        ])->assertCreated();

        $this->actingAsRole('management');
        $this->postJson(route('projects.variations.approve', [$project, $project->variations()->sole()->id]))->assertOk();

        $rows = AuditLog::query()
            ->where('subject_type', (new Project)->getMorphClass())
            ->where('subject_id', $project->id)
            ->whereIn('field', ['contract_value', 'client'])
            ->get();

        $this->assertCount(2, $rows, 'one audit row per changed field, not one per writer');
        $this->assertSame(['VO VO-01: Scope', 'VO VO-01: Scope'], $rows->pluck('reason')->all());
    }

    public function test_attachment_404s_when_the_variation_belongs_to_a_different_project(): void
    {
        $project = $this->project();
        $other = $this->project(['project_code' => 'KPT-RMS-2026-02', 'name' => 'Other']);
        $this->actingAsRole('hr');
        $id = (int) $this->raise($project)->assertStatus(201)->json('variation.id');

        $this->actingAsRole('hr')
            ->get(route('projects.variations.attachment', [$other, $id]))
            ->assertStatus(404);
    }

    public function test_as_of_rejects_a_garbage_date(): void
    {
        $client = ApiClient::create(['tenant_id' => $this->tenant->id, 'name' => 'Track']);
        $plain = $client->mintKey(['projects:read'])->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$plain)
            ->getJson('/api/v1/projects?as_of=not-a-date')
            ->assertStatus(422);
    }

    public function test_as_of_reads_the_right_version_across_two_approved_variations(): void
    {
        $project = $this->project();
        $this->actingAsRole('hr');
        $id1 = (int) $this->raise($project, ['vo_no' => 'VO-01', 'variation_date' => '2026-10-01', 'contract_value' => 1400000])
            ->assertStatus(201)->json('variation.id');
        $id2 = (int) $this->raise($project, ['vo_no' => 'VO-02', 'variation_date' => '2026-11-01', 'contract_value' => 1600000])
            ->assertStatus(201)->json('variation.id');

        $this->actingAsRole('director');
        $this->postJson(route('projects.variations.approve', [$project, $id1]))->assertOk();
        $this->postJson(route('projects.variations.approve', [$project, $id2]))->assertOk();

        $client = ApiClient::create(['tenant_id' => $this->tenant->id, 'name' => 'Track']);
        $plain = $client->mintKey(['projects:read'])->plainTextToken;
        $row = fn (string $asOf) => collect(
            $this->withHeader('Authorization', 'Bearer '.$plain)->getJson('/api/v1/projects?as_of='.$asOf)->assertOk()->json('data')
        )->firstWhere('project_code', 'KPT-RMS-2026-01');

        $this->assertSame('1250000.00', (string) $row('2026-09-01')['contract_value']);
        $this->assertSame('1400000.00', (string) $row('2026-10-15')['contract_value']);
        $this->assertSame('1600000.00', (string) $row('2026-11-15')['contract_value']);
    }

    /** ApiTenant middleware (shared, not owned by this CR): a Sanctum bearer call, once
     *  it succeeds, must not leave the process-wide default auth guard pointed at
     *  'sanctum' — a later guard-less web actingAs() would otherwise poison the
     *  sanctum guard's cached user and 401 every bearer call after it. */
    public function test_a_web_session_action_between_two_bearer_calls_does_not_break_the_second_call(): void
    {
        $client = ApiClient::create(['tenant_id' => $this->tenant->id, 'name' => 'Track']);
        $plain = $client->mintKey(['projects:read'])->plainTextToken;
        $this->project();

        $this->withHeader('Authorization', 'Bearer '.$plain)
            ->getJson('/api/v1/projects')
            ->assertOk();

        $this->actingAsRole('hr')
            ->get(route('app.screen', 'dash'))
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$plain)
            ->getJson('/api/v1/projects')
            ->assertOk();
    }
}
