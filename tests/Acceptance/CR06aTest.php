<?php

namespace Tests\Acceptance;

use App\Models\ApiClient;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Acceptance for docs/specs/CR-06.md, split 6a (session S09, schema + versioning).
 * 6b (variations + Director approval, S10) and 6c (Track pull, deferred) are pinned
 * only as markTestIncomplete placeholders here.
 *
 * Shapes fixed here and logged in OPEN "QA / CR-06a": `projects` gains `project_code`
 * (unique per tenant, immutable after creation, format `/^[A-Z0-9]+(-[A-Z0-9]+)*$/`),
 * `client`, `status` (planning|active|closed), `contract_value`, `procurement_method`,
 * `contractor`, `bond_value`, `bond_submitted_at`, `loa_date`, `loa_ref`, `agreement_date`,
 * `agreement_ref`, `contract_start`, `contract_end`, `drive_link`, `pm_id`, `pe_id`,
 * `closed_at`, `closed_by_id`. `project_versions` (id, tenant_id, project_id, version_no,
 * effective_date, snapshot json, changes json, reason, created_by_id, created_at); version 1
 * on create, a new version per master-field change. Field sets: finance (contract_value,
 * bond_value, bond_submitted_at, loa_date, loa_ref, agreement_date, agreement_ref) editable
 * by hr + management tier (director); PM set (pm_id, pe_id, status, drive_link,
 * procurement_method, contractor) editable by manager + management tier; base set (name,
 * sort, categories, is_active, code) is today's EDITOR_ROLES. contract_value,
 * contract_start, contract_end, client cannot be changed in place (422 naming the
 * Variation path, CR-06b). Existing update route `POST /app/projects/{project}` answers
 * JSON `{ok, version}`. `POST /app/projects/{project}/reopen` is management tier only,
 * reason required, closed only. Artisan `projects:backfill-versions` writes version 1 for
 * legacy rows. `GET /api/v1/projects` (ability projects:read) carries the master fields
 * plus `version`.
 */
class CR06aTest extends TestCase
{
    use AlwaysChecks;
    use RefreshDatabase;

    private Employee $manager;

    private Employee $hr;

    private Employee $director;

    private Employee $staff;

    private Employee $pmPerson;

    private Employee $pePerson;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-08 10:00:00');

        $this->manager = $this->person('Kussairi', 'manager');
        $this->hr = $this->person('Hidayah', 'hr');
        $this->director = $this->person('Shahril', 'director');
        $this->staff = $this->person('Shazwan', 'employee');
        $this->pmPerson = $this->person('PM Person', 'employee');
        $this->pePerson = $this->person('PE Person', 'employee');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function test_acceptance_1_create_with_full_details_is_stored_versioned_audited_and_listed_for_track(): void
    {
        $project = $this->create($this->manager);

        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'project_code' => 'KPT-RMS-2026-01',
            'code' => 'KPT',
            'name' => 'KPT: RMS',
            'client' => 'Kementerian Pengangkutan',
            'status' => 'active',
            'contract_value' => '1250000.00',
            'procurement_method' => 'Open tender',
            'contractor' => 'Unijaya Resources Sdn Bhd',
            'bond_value' => '62500.00',
            'bond_submitted_at' => '2026-07-01',
            'loa_date' => '2026-06-15',
            'loa_ref' => 'LOA/KPT/2026/07',
            'agreement_date' => '2026-07-10',
            'agreement_ref' => 'AGR/KPT/2026/03',
            'contract_start' => '2026-08-01',
            'contract_end' => '2027-07-31',
            'drive_link' => 'https://drive.google.com/drive/folders/abc',
            'pm_id' => $this->pmPerson->id,
            'pe_id' => $this->pePerson->id,
        ]);

        $this->assertSame(1, DB::table('project_versions')->where('project_id', $project->id)->count(), 'expected exactly one version row on create');
        $this->assertDatabaseHas('project_versions', [
            'project_id' => $project->id,
            'version_no' => 1,
            'effective_date' => '2026-09-08',
        ]);
        $snapshot = (string) DB::table('project_versions')->where('project_id', $project->id)->value('snapshot');
        $this->assertStringContainsString('"contract_value"', $snapshot);

        $this->assertNotNull(
            AuditLog::query()->where('subject_type', (new Project)->getMorphClass())->where('subject_id', $project->id)->where('field', 'project_code')->first(),
            'no audit entry for project_code on create'
        );

        $client = ApiClient::create(['tenant_id' => $this->tenant()->id, 'name' => 'Track']);
        $plain = $client->mintKey(['projects:read'])->plainTextToken;
        $response = $this->withHeader('Authorization', 'Bearer '.$plain)->getJson('/api/v1/projects');
        $response->assertOk();
        $rows = $response->json('data');
        $row = collect($rows)->firstWhere('project_code', 'KPT-RMS-2026-01');
        $this->assertNotNull($row, 'the newly created project is not in the Track-facing listing');
        foreach (['project_code', 'client', 'status', 'contract_value', 'contract_start', 'contract_end', 'pm', 'pe', 'version'] as $key) {
            $this->assertArrayHasKey($key, $row, "GET /api/v1/projects is missing key {$key}");
        }
        $this->assertSame(1, $row['version']);

        // employee cannot create
        $this->actingInTenantAs($this->staff)
            ->postJson(route('projects.store'), $this->full(['project_code' => 'KPT-RMS-2026-02']))
            ->assertStatus(403);

        // missing client
        $data = $this->full(['project_code' => 'KPT-RMS-2026-03']);
        unset($data['client']);
        $this->actingInTenantAs($this->manager)->postJson(route('projects.store'), $data)->assertStatus(422);

        // bad project_code format
        $this->actingInTenantAs($this->manager)
            ->postJson(route('projects.store'), $this->full(['project_code' => 'kpt rms']))
            ->assertStatus(422);

        // duplicate project_code in the same tenant
        $this->actingInTenantAs($this->manager)
            ->postJson(route('projects.store'), $this->full(['project_code' => 'KPT-RMS-2026-01', 'name' => 'Another name']))
            ->assertStatus(422);
    }

    #[Test]
    public function test_acceptance_2_track_prefills_project_details(): void
    {
        $this->markTestIncomplete('human check: Track pre-fills Project Details from GET /api/v1/projects; Track side is CR-06c, deferred');
    }

    #[Test]
    public function test_acceptance_3_contract_value_variation_awaiting_approval(): void
    {
        $this->markTestIncomplete('CR-06b (S10): variation dated 1 Oct awaiting approval, October vs August figures; pinned by CR06bTest');
    }

    #[Test]
    public function test_acceptance_4_track_saves_with_only_link_baseline_and_team(): void
    {
        $this->markTestIncomplete('human check: Track saves with only the Amanahku link, baseline and team; Track side is CR-06c, deferred');
    }

    #[Test]
    public function test_acceptance_5_existing_projects_migrate_as_version_1(): void
    {
        $legacy = Project::create([
            'tenant_id' => $this->tenant()->id,
            'code' => 'JKDM',
            'name' => 'JKDM: MyDLV',
            'is_active' => true,
            'created_at' => '2026-01-15 09:00:00',
        ]);

        $this->assertSame(0, DB::table('project_versions')->where('project_id', $legacy->id)->count());

        $this->artisan('projects:backfill-versions')->assertSuccessful();

        $this->assertSame(1, DB::table('project_versions')->where('project_id', $legacy->id)->count());
        $this->assertDatabaseHas('project_versions', [
            'project_id' => $legacy->id,
            'version_no' => 1,
            'effective_date' => '2026-01-15',
        ]);
        $this->assertDatabaseHas('projects', ['id' => $legacy->id, 'client' => 'JKDM']);

        $fresh = $legacy->fresh();
        $this->assertSame(1, $fresh->currentVersion()->version_no);
        $this->assertSame(1, $fresh->versionEffectiveOn('2026-02-01')->version_no);
        $this->assertNull($fresh->versionEffectiveOn('2025-12-31'));

        $this->artisan('projects:backfill-versions')->assertSuccessful();
        $this->assertSame(1, DB::table('project_versions')->where('project_id', $legacy->id)->count(), 'a second run must not duplicate the version');
    }

    #[Test]
    public function test_acceptance_6_field_level_permissions(): void
    {
        $project = $this->create($this->manager);

        // (a) manager cannot touch the finance set
        $r = $this->update($this->manager, $project, ['contract_value' => 2000000]);
        $r->assertStatus(403);
        $this->assertStringContainsString('Contract value', (string) $r->getContent());

        // (b) hr cannot touch the PM set
        $r = $this->update($this->hr, $project, ['pm_id' => $this->pePerson->id]);
        $r->assertStatus(403);
        $this->assertStringContainsString('PM', (string) $r->getContent());

        // (c) staff cannot touch the PM set
        $this->update($this->staff, $project, ['drive_link' => 'https://drive.google.com/drive/folders/xyz'])
            ->assertStatus(403);

        // (d) hr edits the finance set
        $r = $this->update($this->hr, $project, ['bond_value' => 70000]);
        $r->assertOk();
        $r->assertJson(['ok' => true, 'version' => 2]);
        $this->assertDatabaseHas('projects', ['id' => $project->id, 'bond_value' => '70000.00']);
        $changes = (string) DB::table('project_versions')->where('project_id', $project->id)->where('version_no', 2)->value('changes');
        $this->assertStringContainsString('bond_value', $changes);
        $audit = AuditLog::query()->where('subject_type', (new Project)->getMorphClass())->where('subject_id', $project->id)->where('field', 'bond_value')->first();
        $this->assertNotNull($audit, 'no audit entry for bond_value change');
        $this->assertStringContainsString('62500', (string) $audit->old_value);
        $this->assertStringContainsString('70000', (string) $audit->new_value);

        // (e) manager changes pm_id + status with an effective date in the future
        $r = $this->update($this->manager, $project, ['pm_id' => $this->pePerson->id, 'status' => 'planning', 'effective_date' => '2026-10-01']);
        $r->assertOk();
        $r->assertJson(['ok' => true, 'version' => 3]);
        $fresh = $project->fresh();
        $this->assertSame($this->pmPerson->id, $fresh->versionEffectiveOn('2026-09-15')->snapshot['pm_id']);
        $this->assertSame($this->pePerson->id, $fresh->versionEffectiveOn('2026-10-01')->snapshot['pm_id']);

        // (f) director (management tier) can edit both sets in one request
        $r = $this->update($this->director, $project, ['bond_value' => 80000, 'pe_id' => $this->pmPerson->id]);
        $r->assertOk();
        $r->assertJson(['ok' => true, 'version' => 4]);

        // (g) contract_value, contract_start, client cannot be changed in place (E4)
        foreach (['contract_value' => 3000000, 'contract_start' => '2026-09-01', 'client' => 'Someone Else'] as $field => $value) {
            $r = $this->update($this->hr, $project, [$field => $value]);
            $r->assertStatus(422);
            $this->assertStringContainsString('ariation', (string) $r->getContent(), "{$field} should point at the Variation path");
        }

        // (h) project_code is locked
        $r = $this->update($this->manager, $project, ['project_code' => 'KPT-RMS-2027-01']);
        $r->assertStatus(422);
        $this->assertStringContainsString('locked', (string) $r->getContent());

        // (i) name is a master field, editable and versioned
        $r = $this->update($this->manager, $project, ['name' => 'KPT: RMS Phase 2']);
        $r->assertOk();
        $this->assertGreaterThan(4, $r->json('version'));
    }

    #[Test]
    public function test_acceptance_7_closed_project_is_locked_until_director_reopens_with_reason(): void
    {
        $project = $this->create($this->manager);

        $r = $this->update($this->manager, $project, ['status' => 'closed']);
        $r->assertOk();
        $closed = $project->fresh();
        $this->assertNotNull($closed->closed_at);
        $this->assertGreaterThan(1, DB::table('project_versions')->where('project_id', $project->id)->count());

        $r = $this->update($this->hr, $project, ['bond_value' => 90000]);
        $r->assertStatus(422);
        $this->assertStringContainsString('closed', (string) $r->getContent());

        $r = $this->update($this->manager, $project, ['drive_link' => 'https://drive.google.com/drive/folders/qqq']);
        $r->assertStatus(422);
        $this->assertStringContainsString('closed', (string) $r->getContent());

        // reopening
        $this->actingInTenantAs($this->manager)
            ->postJson(route('projects.reopen', $project), ['reason' => 'Extension signed'])
            ->assertStatus(403);

        $this->actingInTenantAs($this->director)
            ->postJson(route('projects.reopen', $project), [])
            ->assertStatus(422);

        $this->actingInTenantAs($this->director)
            ->postJson(route('projects.reopen', $project), ['reason' => 'Extension signed'])
            ->assertOk();

        $reopened = $project->fresh();
        $this->assertSame('active', $reopened->status);
        $this->assertNull($reopened->closed_at);

        $lastVersionNo = DB::table('project_versions')->where('project_id', $project->id)->max('version_no');
        $this->assertSame('Extension signed', DB::table('project_versions')->where('project_id', $project->id)->where('version_no', $lastVersionNo)->value('reason'));

        $audit = AuditLog::query()->where('subject_type', (new Project)->getMorphClass())->where('subject_id', $project->id)->where('field', 'status')->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertSame(json_encode('closed'), $audit->old_value);
        $this->assertSame(json_encode('active'), $audit->new_value);
        $this->assertSame('Extension signed', $audit->reason);

        // now the finance set works again
        $this->update($this->hr, $project, ['bond_value' => 90000])->assertOk();
    }

    #[Test]
    public function test_always_checks(): void
    {
        $this->assertDueDateLocked();
        $this->assertAuditLogImmutable();
        $this->assertDashboardUnchanged();
        $this->assertKeepItPlainHonoured();
    }

    // ── helpers ──────────────────────────────────────────────────────

    /** A complete project-create payload for the fixture project KPT: RMS. */
    private function full(array $overrides = []): array
    {
        return array_merge([
            'project_code' => 'KPT-RMS-2026-01',
            'code' => 'KPT',
            'name' => 'KPT: RMS',
            'client' => 'Kementerian Pengangkutan',
            'status' => 'active',
            'contract_value' => 1250000.00,
            'procurement_method' => 'Open tender',
            'contractor' => 'Unijaya Resources Sdn Bhd',
            'bond_value' => 62500.00,
            'bond_submitted_at' => '2026-07-01',
            'loa_date' => '2026-06-15',
            'loa_ref' => 'LOA/KPT/2026/07',
            'agreement_date' => '2026-07-10',
            'agreement_ref' => 'AGR/KPT/2026/03',
            'contract_start' => '2026-08-01',
            'contract_end' => '2027-07-31',
            'drive_link' => 'https://drive.google.com/drive/folders/abc',
            'pm_id' => $this->pmPerson->id,
            'pe_id' => $this->pePerson->id,
            'sort' => 1,
        ], $overrides);
    }

    private function create(Employee $as, array $overrides = []): Project
    {
        $payload = $this->full($overrides);
        $this->actingInTenantAs($as)->postJson(route('projects.store'), $payload)->assertSuccessful();

        return Project::where('project_code', $payload['project_code'])->firstOrFail();
    }

    private function update(Employee $as, Project $p, array $data): TestResponse
    {
        return $this->actingInTenantAs($as)->postJson(route('projects.update', $p), $data);
    }
}
