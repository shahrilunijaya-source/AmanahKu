<?php

namespace Tests\Acceptance;

use App\Models\ApiClient;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Acceptance for docs/specs/CR-06.md, split 6b (session S10, variations + Director
 * approval, spec parts E4 and E5). Shapes fixed in OPEN "QA / CR-06b / shapes fixed by
 * CR06bTest": table `project_variations` (vo_no, variation_date, reason, changes json over
 * contract_value / contract_start / contract_end / client, delta, attachment_path, status
 * pending|approved|rejected, raised_by_id, decided_by_id, decided_at, decision_note,
 * version_id); `POST /app/projects/{project}/variations` (hr + management tier) raises one
 * pending, `.../variations/{variation}/approve` and `.../reject` (management tier only)
 * decide it; approval applies the change, writes the next version with effective_date =
 * variation_date and one audit row per field; `GET /api/v1/projects?as_of=YYYY-MM-DD`
 * answers the version effective on that date and every row carries `awaiting_approval`.
 *
 * Only acceptance item 3 of the spec belongs to 6b; items 1, 5, 6, 7 are CR06aTest and
 * items 2 and 4 are Track-side human checks. The E4 and E5 governance rules the item
 * leans on get their own tests below so a failure names the rule.
 */
class CR06bTest extends TestCase
{
    use AlwaysChecks;
    use RefreshDatabase;

    private Employee $manager;

    private Employee $hr;

    private Employee $director;

    private Employee $staff;

    private Employee $pmPerson;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-08 10:00:00');

        $this->manager = $this->person('Kussairi', 'manager');
        $this->hr = $this->person('Hidayah', 'hr');
        $this->director = $this->person('Shahril', 'director');
        $this->staff = $this->person('Shazwan', 'employee');
        $this->pmPerson = $this->person('PM Person', 'employee');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function test_acceptance_3_contract_value_variation_dated_1_oct_awaits_approval_then_october_reads_new_and_august_reads_old(): void
    {
        // Version 1 is effective 1 June, well before the August report.
        Carbon::setTestNow('2026-06-01 09:00:00');
        $project = $this->create();
        Carbon::setTestNow('2026-09-08 10:00:00');

        // Finance raises a contract-value variation dated 1 Oct.
        $r = $this->raise($this->hr, $project, [
            'vo_no' => 'VO-01',
            'variation_date' => '2026-10-01',
            'reason' => 'Additional scope for module 3',
            'contract_value' => 1500000,
        ]);
        $r->assertStatus(201);
        $r->assertJsonPath('ok', true);
        $r->assertJsonPath('variation.status', 'pending');
        $variationId = (int) $r->json('variation.id');

        $this->assertDatabaseHas('project_variations', [
            'id' => $variationId,
            'project_id' => $project->id,
            'vo_no' => 'VO-01',
            'variation_date' => '2026-10-01',
            'status' => 'pending',
            'delta' => '250000.00',
            'raised_by_id' => $this->hr->user_id,
        ]);
        // Nothing moves while it waits.
        $this->assertDatabaseHas('projects', ['id' => $project->id, 'contract_value' => '1250000.00']);
        $this->assertSame(1, DB::table('project_versions')->where('project_id', $project->id)->count(), 'a pending variation must not write a version');

        // The register shows it as awaiting approval, for finance and for the director.
        $this->actingInTenantAs($this->hr)->get('/app/projects')->assertOk()->assertSee('Awaiting approval');
        $this->actingInTenantAs($this->director)->get('/app/projects')->assertOk()->assertSee('Awaiting approval');

        // Track sees the old value plus the pending count.
        $before = $this->trackRow($project);
        $this->assertSame('1250000.00', (string) $before['contract_value']);
        $this->assertSame(1, $before['awaiting_approval']);
        $this->assertSame(1, $before['version']);

        // Director approves.
        $r = $this->actingInTenantAs($this->director)->postJson(route('projects.variations.approve', [$project, $variationId]));
        $r->assertOk();
        $r->assertJson(['ok' => true, 'version' => 2]);

        $this->assertDatabaseHas('projects', ['id' => $project->id, 'contract_value' => '1500000.00']);
        $this->assertDatabaseHas('project_versions', [
            'project_id' => $project->id,
            'version_no' => 2,
            'effective_date' => '2026-10-01',
        ]);
        $version2 = DB::table('project_versions')->where('project_id', $project->id)->where('version_no', 2)->first();
        $this->assertStringContainsString('VO-01', (string) $version2->reason);
        $this->assertStringContainsString('contract_value', (string) $version2->changes);
        $this->assertDatabaseHas('project_variations', [
            'id' => $variationId,
            'status' => 'approved',
            'decided_by_id' => $this->director->user_id,
            'version_id' => $version2->id,
        ]);
        $this->assertNotNull(DB::table('project_variations')->where('id', $variationId)->value('decided_at'));

        // Original value retained on version 1.
        $fresh = $project->fresh();
        $this->assertSame('1250000.00', (string) $fresh->versionEffectiveOn('2026-08-15')->snapshot['contract_value']);
        $this->assertSame('1500000.00', (string) $fresh->versionEffectiveOn('2026-10-15')->snapshot['contract_value']);

        // Audit: the field change carries the VO reason and the director.
        $audit = AuditLog::query()->where('subject_type', (new Project)->getMorphClass())->where('subject_id', $project->id)->where('field', 'contract_value')->latest('id')->first();
        $this->assertNotNull($audit, 'no audit row for the approved contract_value change');
        $this->assertStringContainsString('1250000', (string) $audit->old_value);
        $this->assertStringContainsString('1500000', (string) $audit->new_value);
        $this->assertStringContainsString('VO-01', (string) $audit->reason);
        $this->assertSame($this->director->user_id, $audit->user_id);

        // Track: the October report reads the new value, the August weekly report the old one.
        $october = $this->trackRow($project, '2026-10-15');
        $this->assertSame('1500000.00', (string) $october['contract_value']);
        $this->assertSame(2, $october['version']);
        $this->assertSame(0, $october['awaiting_approval']);

        $august = $this->trackRow($project, '2026-08-14');
        $this->assertSame('1250000.00', (string) $august['contract_value']);
        $this->assertSame(1, $august['version']);

        $today = $this->trackRow($project);
        $this->assertSame('1500000.00', (string) $today['contract_value']);
        $this->assertSame(2, $today['version']);

        // A date before the project existed does not list it at all.
        $rows = collect($this->track('2026-05-01')->json('data'));
        $this->assertNull($rows->firstWhere('project_code', 'KPT-RMS-2026-01'));
    }

    #[Test]
    public function test_e4_variation_records_are_the_only_way_to_move_contract_terms(): void
    {
        Storage::fake('local');
        $project = $this->create();

        // In-place edits stay blocked (E4, already pinned by CR06aTest 6g).
        $this->actingInTenantAs($this->hr)->postJson(route('projects.update', $project), ['contract_value' => 2000000])->assertStatus(422);

        // Who may raise: finance (hr) and the management tier; not the PM, not staff.
        $this->raise($this->manager, $project, $this->vo())->assertStatus(403);
        $this->raise($this->staff, $project, $this->vo())->assertStatus(403);

        // Validation: VO number, date and reason are required; at least one term must move.
        $this->raise($this->hr, $project, $this->vo(['vo_no' => '']))->assertStatus(422);
        $this->raise($this->hr, $project, $this->vo(['variation_date' => '']))->assertStatus(422);
        $this->raise($this->hr, $project, $this->vo(['reason' => '']))->assertStatus(422);
        $this->raise($this->hr, $project, ['vo_no' => 'VO-02', 'variation_date' => '2026-10-01', 'reason' => 'No field moved'])->assertStatus(422);
        $this->raise($this->hr, $project, $this->vo(['contract_end' => '2026-07-01']))->assertStatus(422); // before the 2026-08-01 start

        // A VO can move several terms at once, with an attachment; delta follows the value.
        $r = $this->raise($this->director, $project, $this->vo([
            'vo_no' => 'VO-07',
            'contract_value' => 1100000,
            'contract_end' => '2027-12-31',
            'client' => 'Kementerian Pengangkutan Malaysia',
            'attachment' => UploadedFile::fake()->create('vo-07.pdf', 120, 'application/pdf'),
        ]), multipart: true);
        $r->assertStatus(201);
        $id = (int) $r->json('variation.id');
        $row = DB::table('project_variations')->where('id', $id)->first();
        $this->assertSame('-150000.00', (string) $row->delta);
        $this->assertNotNull($row->attachment_path);
        Storage::disk('local')->assertExists($row->attachment_path);
        $changes = json_decode((string) $row->changes, true);
        $this->assertSame(['contract_value', 'contract_end', 'client'], array_keys($changes));
        $this->assertSame('2027-07-31', (string) $changes['contract_end']['old']);
        $this->assertSame('2027-12-31', (string) $changes['contract_end']['new']);

        // The attachment streams back for someone who can open the register.
        $this->actingInTenantAs($this->hr)->get(route('projects.variations.attachment', [$project, $id]))->assertOk();

        // Raising writes an audit row naming the VO.
        $audit = AuditLog::query()->where('subject_type', (new Project)->getMorphClass())->where('subject_id', $project->id)->where('field', 'variation')->latest('id')->first();
        $this->assertNotNull($audit, 'raising a variation must be audited');
        $this->assertStringContainsString('VO-07', (string) $audit->new_value);

        // VO numbers are unique per project.
        $this->raise($this->hr, $project, $this->vo(['vo_no' => 'VO-07']))->assertStatus(422);

        // A closed project takes no variations.
        $this->actingInTenantAs($this->manager)->postJson(route('projects.update', $project), ['status' => 'closed'])->assertOk();
        $r = $this->raise($this->hr, $project, $this->vo(['vo_no' => 'VO-08']));
        $r->assertStatus(422);
        $this->assertStringContainsString('closed', (string) $r->getContent());

        // A variation approved after the project reopens still keeps the original on version 1.
        $this->actingInTenantAs($this->director)->postJson(route('projects.reopen', $project), ['reason' => 'Extension signed'])->assertOk();
        $this->actingInTenantAs($this->director)->postJson(route('projects.variations.approve', [$project, $id]))->assertOk();
        $fresh = $project->fresh();
        $this->assertSame('1250000.00', (string) $fresh->versions()->where('version_no', 1)->first()->snapshot['contract_value']);
        $this->assertSame('1100000.00', (string) $fresh->contract_value);
        $this->assertSame('2027-12-31', $fresh->contract_end->toDateString());
        $this->assertSame('Kementerian Pengangkutan Malaysia', $fresh->client);
    }

    #[Test]
    public function test_e5_only_the_management_tier_decides_and_a_rejection_changes_nothing(): void
    {
        $project = $this->create();
        $other = $this->create(['project_code' => 'KPT-RMS-2026-02', 'name' => 'KPT: RMS 2']);

        $id = (int) $this->raise($this->hr, $project, $this->vo(['vo_no' => 'VO-03']))->assertStatus(201)->json('variation.id');

        // Neither finance nor the PM may decide.
        $this->actingInTenantAs($this->hr)->postJson(route('projects.variations.approve', [$project, $id]))->assertStatus(403);
        $this->actingInTenantAs($this->hr)->postJson(route('projects.variations.reject', [$project, $id]))->assertStatus(403);
        $this->actingInTenantAs($this->manager)->postJson(route('projects.variations.approve', [$project, $id]))->assertStatus(403);
        $this->actingInTenantAs($this->staff)->postJson(route('projects.variations.approve', [$project, $id]))->assertStatus(403);

        // The variation must belong to the project in the URL.
        $this->actingInTenantAs($this->director)->postJson(route('projects.variations.approve', [$other, $id]))->assertStatus(404);

        // Reject with a note: no version, project untouched, audit row, note kept.
        $r = $this->actingInTenantAs($this->director)->postJson(route('projects.variations.reject', [$project, $id]), ['note' => 'Scope not agreed with client']);
        $r->assertOk();
        $this->assertDatabaseHas('project_variations', [
            'id' => $id,
            'status' => 'rejected',
            'decided_by_id' => $this->director->user_id,
            'decision_note' => 'Scope not agreed with client',
            'version_id' => null,
        ]);
        $this->assertDatabaseHas('projects', ['id' => $project->id, 'contract_value' => '1250000.00']);
        $this->assertSame(1, DB::table('project_versions')->where('project_id', $project->id)->count());
        $audit = AuditLog::query()->where('subject_type', (new Project)->getMorphClass())->where('subject_id', $project->id)->where('field', 'variation')->latest('id')->first();
        $this->assertStringContainsString('rejected', (string) $audit->new_value);

        // A decided variation cannot be decided again.
        $this->actingInTenantAs($this->director)->postJson(route('projects.variations.approve', [$project, $id]))->assertStatus(422);
        $this->actingInTenantAs($this->director)->postJson(route('projects.variations.reject', [$project, $id]))->assertStatus(422);

        // The register no longer flags it as awaiting approval, and Track's count is 0.
        $this->actingInTenantAs($this->hr)->get('/app/projects')->assertOk()->assertDontSee('Awaiting approval');
        $this->assertSame(0, $this->trackRow($project)['awaiting_approval']);

        // A second VO can reuse nothing of the rejected one's number, but approval by the
        // director works on a fresh one and bumps the version.
        $id2 = (int) $this->raise($this->hr, $project, $this->vo(['vo_no' => 'VO-04']))->assertStatus(201)->json('variation.id');
        $this->actingInTenantAs($this->director)->postJson(route('projects.variations.approve', [$project, $id2]))->assertOk()->assertJson(['ok' => true, 'version' => 2]);
        $this->actingInTenantAs($this->director)->postJson(route('projects.variations.approve', [$project, $id2]))->assertStatus(422);
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

    /** The fixture project KPT: RMS with every master field, created by the manager. */
    private function create(array $overrides = []): Project
    {
        $payload = array_merge([
            'project_code' => 'KPT-RMS-2026-01',
            'code' => 'KPT',
            'name' => 'KPT: RMS',
            'client' => 'Kementerian Pengangkutan',
            'status' => 'active',
            'contract_value' => 1250000.00,
            'procurement_method' => 'Open tender',
            'contractor' => 'Unijaya Resources Sdn Bhd',
            'bond_value' => 62500.00,
            'contract_start' => '2026-08-01',
            'contract_end' => '2027-07-31',
            'pm_id' => $this->pmPerson->id,
            'sort' => 1,
        ], $overrides);
        $this->actingInTenantAs($this->manager)->postJson(route('projects.store'), $payload)->assertSuccessful();

        return Project::query()->where('tenant_id', $this->tenant()->id)->where('project_code', $payload['project_code'])->firstOrFail();
    }

    /** A valid contract-value variation payload. */
    private function vo(array $overrides = []): array
    {
        return array_merge([
            'vo_no' => 'VO-01',
            'variation_date' => '2026-10-01',
            'reason' => 'Additional scope for module 3',
            'contract_value' => 1500000,
        ], $overrides);
    }

    private function raise(Employee $as, Project $project, array $data, bool $multipart = false): TestResponse
    {
        $this->actingInTenantAs($as);

        return $multipart
            ? $this->withHeader('Accept', 'application/json')->post(route('projects.variations.store', $project), $data)
            : $this->postJson(route('projects.variations.store', $project), $data);
    }

    /** GET /api/v1/projects as Track, optionally for a reporting date. */
    private function track(?string $asOf = null): TestResponse
    {
        $client = ApiClient::create(['tenant_id' => $this->tenant()->id, 'name' => 'Track '.uniqid()]);
        $plain = $client->mintKey(['projects:read'])->plainTextToken;

        return $this->withHeader('Authorization', 'Bearer '.$plain)
            ->getJson('/api/v1/projects'.($asOf ? '?as_of='.$asOf : ''))
            ->assertOk();
    }

    /** @return array<string, mixed> */
    private function trackRow(Project $project, ?string $asOf = null): array
    {
        $row = collect($this->track($asOf)->json('data'))->firstWhere('project_code', $project->project_code);
        $this->assertNotNull($row, "project {$project->project_code} missing from GET /api/v1/projects".($asOf ? " as_of={$asOf}" : ''));
        foreach (['contract_value', 'version', 'awaiting_approval'] as $key) {
            $this->assertArrayHasKey($key, $row, "GET /api/v1/projects is missing key {$key}");
        }

        return $row;
    }
}
