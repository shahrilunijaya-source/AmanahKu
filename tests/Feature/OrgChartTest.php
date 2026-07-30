<?php

namespace Tests\Feature;

use App\Http\Controllers\OrgController;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
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

    public function test_orgchart_ships_the_whole_reporting_graph_to_the_navigator(): void
    {
        // Act
        $response = $this->actingInTenant()->get('/app/orgchart');

        // Assert — the screen navigates one seat at a time in the browser, so the manager
        // and everyone under her must be in the payload even though only one ring of the
        // chart is drawn at a time.
        $response->assertOk();
        $response->assertSee('Aisyah Rahman');           // root
        $response->assertSee('Nurul Iman binti Hassan'); // child
        $response->assertSee('Farah Aziz');              // child
    }

    public function test_a_seat_shows_a_face_and_carries_the_name_for_hover_and_focus(): void
    {
        $html = $this->actingInTenant()->get('/app/orgchart')->assertOk()->getContent();

        // A seat holds the person's photo or initials, never their name as circle text.
        $this->assertStringContainsString('class="oc-av"', $html);
        // The name rides on data-name, which the ::after tooltip reveals on hover AND on
        // keyboard focus — hover alone would strand touch and keyboard users.
        $this->assertStringContainsString(':data-name="person(id).name"', $html);
        $this->assertStringContainsString(':data-name="subject.name"', $html);
        // The only word left inside a circle belongs to the band, which is not a person.
        $this->assertSame(1, substr_count($html, 'class="oc-name"'));
    }

    public function test_a_management_tier_account_pins_to_the_directors_band(): void
    {
        // A user whose only tenant role is `management` (no director role, no director
        // position band) must still land in the leadership band — permissions already
        // collapse director → management, and the chart mirrors that collapse.
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $boss = User::create(['name' => 'Head of Ops', 'email' => 'head@acme.example', 'password' => Hash::make('password')]);
        $boss->tenants()->attach($tenant->id, ['role' => 'management']);
        $employee = Employee::create([
            'tenant_id' => $tenant->id, 'user_id' => $boss->id,
            'name' => 'Head of Ops', 'status' => 'active', 'workload' => 'green',
        ]);

        app(CurrentTenant::class)->set($tenant);
        $this->actingAs($boss)->withSession(['current_tenant' => $tenant->id]);

        $data = app(OrgController::class)->screenData(Request::create('/app/orgchart'), $employee);

        $this->assertContains($employee->id, $data['chart']['directors']);
    }

    public function test_orgchart_shows_the_summary_line(): void
    {
        // Act
        $response = $this->actingInTenant()->get('/app/orgchart');

        // Assert — headcount and depth, in the one mono line that replaced the stat cards.
        $response->assertOk();
        $response->assertSee('staff');
        $response->assertSee('levels deep');
    }
}
