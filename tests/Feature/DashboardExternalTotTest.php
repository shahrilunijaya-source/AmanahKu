<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Employee;
use App\Models\ExternalTotEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FeatureManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The dashboard's "Announcements" rail card merges recent External TOT events
 * alongside real announcements (BuildsDashboardData::newsRows). See
 * TotController for the External tab itself — this only covers the dashboard brief.
 */
class DashboardExternalTotTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $this->user = User::create(['name' => 'Demo', 'email' => 'demo@example.com', 'password' => Hash::make('password')]);
        $this->user->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->user->id,
            'name' => 'Demo', 'status' => 'active', 'workload' => 'green',
        ]);
    }

    private function dash(): TestResponse
    {
        return $this->actingAs($this->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/dash?scope=me');
    }

    private function event(array $overrides = []): ExternalTotEvent
    {
        return ExternalTotEvent::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'title' => 'Cybersecurity in the Age of NeoCloud',
            'host' => 'Techdata Systems',
            'event_date' => now()->addDays(4)->toDateString(),
        ], $overrides));
    }

    public function test_an_upcoming_external_event_appears_on_the_dashboard(): void
    {
        $this->event();

        $this->dash()->assertOk()
            ->assertSee('Cybersecurity in the Age of NeoCloud')
            ->assertSee('External TOT');
    }

    public function test_the_brief_does_not_carry_the_full_description(): void
    {
        $this->event(['description' => 'A secret agenda line nobody should see from the dashboard.']);

        $this->dash()->assertOk()
            ->assertDontSee('A secret agenda line nobody should see from the dashboard.');
    }

    public function test_a_past_external_event_drops_off_the_dashboard(): void
    {
        $this->event(['event_date' => now()->subDay()->toDateString()]);

        $this->dash()->assertOk()->assertDontSee('Cybersecurity in the Age of NeoCloud');
    }

    public function test_the_brief_is_hidden_when_the_tot_module_is_off(): void
    {
        $this->event();
        app(FeatureManager::class)->setTenant($this->tenant, 'module.knowledge', false);

        $this->dash()->assertOk()->assertDontSee('Cybersecurity in the Age of NeoCloud');
    }

    public function test_announcements_still_show_when_the_tot_module_is_off(): void
    {
        Announcement::create([
            'tenant_id' => $this->tenant->id, 'title' => 'Q3 town hall recap', 'date' => now()->toDateString(),
        ]);
        app(FeatureManager::class)->setTenant($this->tenant, 'module.knowledge', false);

        $this->dash()->assertOk()->assertSee('Q3 town hall recap');
    }

    public function test_the_brief_counts_down_instead_of_printing_a_bare_date(): void
    {
        $this->event(['event_date' => now()->addDays(3)->toDateString()]);

        $this->dash()->assertOk()->assertSee('in 3 days');
    }

    public function test_a_tagged_viewer_gets_a_required_flag_and_others_do_not(): void
    {
        $me = Employee::where('user_id', $this->user->id)->firstOrFail();
        $this->event(['tagged_employee_ids' => [$me->id]]);

        $this->dash()->assertOk()->assertSee('Required');

        $other = User::create(['name' => 'Other', 'email' => 'other@example.com', 'password' => Hash::make('password')]);
        $other->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $other->id,
            'name' => 'Other', 'status' => 'active', 'workload' => 'green',
        ]);

        $this->actingAs($other)->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/dash?scope=me')->assertOk()->assertDontSee('uj-dq-flag', false);
    }
}
