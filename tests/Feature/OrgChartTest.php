<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature coverage for the recursive org-chart screen.
 *
 * Unlike the write-path suites that hand-build a tenant, this drives the real
 * DatabaseSeeder so the seeded reporting lines (Aisyah → Nurul / Farah / Siti)
 * are exercised end-to-end through the OrgController tree builder.
 */
class OrgChartTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);

        $this->user = User::where('email', 'aisyah.rahman@unijaya.example')->firstOrFail();
        $this->tenant = Tenant::where('slug', 'unijaya')->firstOrFail();
    }

    private function actingInTenant(): self
    {
        $this->actingAs($this->user)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    public function test_orgchart_screen_loads(): void
    {
        // Act
        $response = $this->actingInTenant()->get('/app/orgchart');

        // Assert
        $response->assertOk();
    }

    public function test_orgchart_renders_root_and_nested_reports(): void
    {
        // Act
        $response = $this->actingInTenant()->get('/app/orgchart');

        // Assert — the root manager and her direct reports all render in one tree.
        $response->assertOk();
        $response->assertSee('Aisyah Rahman');      // root
        $response->assertSee('Nurul Iman binti Hassan'); // child
        $response->assertSee('Farah Aziz');          // child
    }

    public function test_orgchart_shows_summary_strip(): void
    {
        // Act
        $response = $this->actingInTenant()->get('/app/orgchart');

        // Assert — aggregate labels from the summary strip are present.
        $response->assertOk();
        $response->assertSee('Headcount');
        $response->assertSee('Reporting depth');
    }
}
