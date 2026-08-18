<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\ApiClient;
use App\Models\Employee;
use App\Models\PersonalAccessToken;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\TimesheetCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Coverage for machine API keys: a key belongs to an app, not to a person, and
 * carries an explicit list of scopes rather than inheriting a staff member's role.
 */
class ApiClientKeyTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'alpha', 'name' => 'Alpha', 'initials' => 'AL']);
    }

    public function test_minting_stamps_the_clients_tenant_on_the_token(): void
    {
        $client = ApiClient::create(['tenant_id' => $this->tenant->id, 'name' => 'Track']);

        $plain = $client->mintKey(['projects:read'])->plainTextToken;

        // findToken() takes the whole "{id}|{secret}" string and splits it itself.
        $token = PersonalAccessToken::findToken($plain);

        $this->assertNotNull($token);
        $this->assertSame($this->tenant->id, $token->tenant_id);
        $this->assertSame(ApiClient::class, $token->tokenable_type);
        $this->assertSame(['projects:read'], $token->abilities);
    }

    public function test_deleting_a_client_deletes_its_keys(): void
    {
        $client = ApiClient::create(['tenant_id' => $this->tenant->id, 'name' => 'Track']);
        $client->mintKey(['projects:read']);

        $this->assertSame(1, PersonalAccessToken::count());

        $client->delete();

        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_an_app_key_reads_a_granted_scope(): void
    {
        Project::create(['tenant_id' => $this->tenant->id, 'name' => 'iLPF', 'code' => 'UJ-1', 'is_active' => true, 'sort' => 1]);

        $client = ApiClient::create(['tenant_id' => $this->tenant->id, 'name' => 'Track']);
        $plain = $client->mintKey(['projects:read'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$plain)->getJson('/api/v1/projects');

        $response->assertOk();
        $this->assertSame('iLPF', $response->json('data.0.name'));
    }

    public function test_a_key_whose_client_moved_tenant_is_rejected(): void
    {
        $other = Tenant::create(['slug' => 'beta', 'name' => 'Beta', 'initials' => 'BE']);

        $client = ApiClient::create(['tenant_id' => $this->tenant->id, 'name' => 'Track']);
        $plain = $client->mintKey(['projects:read'])->plainTextToken;

        // The token still carries tenant A; the client now says tenant B. The two must
        // agree or the key is dead — otherwise re-homing a client silently leaves a
        // working key pointed at the tenant it used to belong to.
        $client->forceFill(['tenant_id' => $other->id])->save();

        $this->withHeader('Authorization', 'Bearer '.$plain)
            ->getJson('/api/v1/projects')
            ->assertStatus(401);
    }

    public function test_an_app_key_cannot_see_another_companys_projects(): void
    {
        $other = Tenant::create(['slug' => 'beta', 'name' => 'Beta', 'initials' => 'BE']);
        Project::create(['tenant_id' => $other->id, 'name' => 'Beta Secret', 'code' => 'B-1', 'is_active' => true, 'sort' => 1]);
        Project::create(['tenant_id' => $this->tenant->id, 'name' => 'iLPF', 'code' => 'UJ-1', 'is_active' => true, 'sort' => 1]);

        $client = ApiClient::create(['tenant_id' => $this->tenant->id, 'name' => 'Track']);
        $plain = $client->mintKey(['projects:read'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$plain)->getJson('/api/v1/projects');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertStringNotContainsString('Beta Secret', $response->getContent() ?: '');
    }

    public function test_an_app_key_survives_every_employee_being_archived(): void
    {
        // The regression the whole design exists for. A key minted against a staff
        // account dies the moment that person is archived (ApiTenant treats it as
        // revoked membership); a client key has no person to lose.
        Project::create(['tenant_id' => $this->tenant->id, 'name' => 'iLPF', 'code' => 'UJ-1', 'is_active' => true, 'sort' => 1]);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Ali', 'status' => 'active', 'workload' => 'green',
        ]);

        $client = ApiClient::create(['tenant_id' => $this->tenant->id, 'name' => 'Track']);
        $plain = $client->mintKey(['projects:read'])->plainTextToken;

        // Archive every last one, so the update below is not a no-op against an empty
        // table — the point is that a real archived roster changes nothing for an app key.
        $archived = Employee::query()->update(['status' => 'archived']);
        $this->assertSame(1, $archived);

        $this->withHeader('Authorization', 'Bearer '.$plain)
            ->getJson('/api/v1/projects')
            ->assertOk();
    }

    public function test_an_app_key_without_the_scope_is_refused_and_returns_no_data(): void
    {
        Project::create(['tenant_id' => $this->tenant->id, 'name' => 'iLPF', 'code' => 'UJ-1', 'is_active' => true, 'sort' => 1]);

        $client = ApiClient::create(['tenant_id' => $this->tenant->id, 'name' => 'SupportOS']);
        $plain = $client->mintKey(['employees:read'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$plain)->getJson('/api/v1/projects');

        $response->assertStatus(403);
        $this->assertNull($response->json('data'));
        $this->assertStringNotContainsString('iLPF', $response->getContent() ?: '');
    }

    public function test_an_app_key_sees_the_whole_tenant_not_an_empty_own_records_list(): void
    {
        // The regression this whole task exists for. A machine caller has no employee
        // record, so the own-records branch would hand back [] behind a healthy 200,
        // and a granted scope would look like a working integration returning nothing.
        Employee::create([
            'tenant_id' => $this->tenant->id, 'name' => 'Ali', 'status' => 'active', 'workload' => 'green',
        ]);

        $client = ApiClient::create(['tenant_id' => $this->tenant->id, 'name' => 'Track']);
        $plain = $client->mintKey(['employees:read'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$plain)->getJson('/api/v1/employees');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Ali', $response->json('data.0.name'));
    }

    public function test_projects_carry_their_category_tags_and_an_untagged_project_carries_none(): void
    {
        $dev = TimesheetCategory::create(['tenant_id' => $this->tenant->id, 'name' => 'Development', 'is_active' => true, 'sort' => 1]);
        $maint = TimesheetCategory::create(['tenant_id' => $this->tenant->id, 'name' => 'Maintenance', 'is_active' => true, 'sort' => 2]);

        $tagged = Project::create(['tenant_id' => $this->tenant->id, 'name' => 'iLPF', 'code' => 'UJ-1', 'is_active' => true, 'sort' => 1]);
        $tagged->categories()->sync([$dev->id, $maint->id]);

        Project::create(['tenant_id' => $this->tenant->id, 'name' => 'Untagged', 'code' => 'UJ-2', 'is_active' => true, 'sort' => 2]);

        $client = ApiClient::create(['tenant_id' => $this->tenant->id, 'name' => 'Track']);
        $plain = $client->mintKey(['projects:read'])->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$plain)->getJson('/api/v1/projects');

        $response->assertOk();
        $this->assertSame(['Development', 'Maintenance'], $response->json('data.0.categories'));
        $this->assertSame([], $response->json('data.1.categories'));
    }
}
