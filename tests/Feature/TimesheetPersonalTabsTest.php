<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TimesheetPersonalTabsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-06-19 12:00:00');

        $this->tenant = Tenant::create([
            'slug' => 'unijaya',
            'name' => 'Unijaya',
            'initials' => 'UJ',
        ]);

        $this->user = User::create([
            'name' => 'Aisyah Rahman',
            'email' => 'aisyah@example.com',
            'password' => Hash::make('password'),
        ]);
        $this->user->tenants()->attach($this->tenant->id, ['role' => 'employee']);

        Employee::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'name' => 'Aisyah Rahman',
            'status' => 'active',
            'workload' => 'green',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function actingInTenant(User $user): self
    {
        $this->actingAs($user)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    public function test_the_screen_offers_record_and_review_tabs(): void
    {
        $r = $this->actingInTenant($this->user)->get('/app/timesheets');

        $r->assertOk();
        $r->assertSee('Record', false);
        $r->assertSee('Review', false);
    }

    public function test_record_is_the_default_tab(): void
    {
        $this->actingInTenant($this->user)->get('/app/timesheets')
            ->assertOk()->assertSee("tab: 'record'", false);
    }

    public function test_tab_review_deep_links(): void
    {
        $this->actingInTenant($this->user)->get('/app/timesheets?tab=review')
            ->assertOk()->assertSee("tab: 'review'", false);
    }

    public function test_an_unknown_tab_falls_back_to_record(): void
    {
        $this->actingInTenant($this->user)->get('/app/timesheets?tab=nonsense')
            ->assertOk()->assertSee("tab: 'record'", false);
    }

    public function test_the_record_tab_shows_a_week_shelf(): void
    {
        $this->actingInTenant($this->user)->get('/app/timesheets')
            ->assertOk()->assertSee('of the week allocated', false);
    }
}
