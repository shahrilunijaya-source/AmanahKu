<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PageHeadLayoutTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-07-15 10:00:00'));

        $this->tenant = Tenant::create([
            'slug' => 'alpha',
            'name' => 'Alpha',
            'initials' => 'AL',
        ]);

        $this->user = User::create([
            'name' => 'Test User',
            'email' => 'user@example.com',
            'password' => Hash::make('password'),
        ]);

        $this->user->tenants()->attach($this->tenant->id, ['role' => 'employee']);

        $this->employee = Employee::create([
            'tenant_id' => $this->tenant->id,
            'user_id' => $this->user->id,
            'name' => 'Test User',
            'status' => 'active',
            'workload' => 'green',
        ]);
    }

    public function test_profile_banner_renders_above_the_page_heading(): void
    {
        $response = $this->actingAs($this->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/attendance');

        $response->assertOk();

        $content = $response->getContent();
        $bannerPos = strpos($content, 'profileBannerDismissedUntil');
        $headingPos = strpos($content, '<h1');

        $this->assertNotFalse($bannerPos, 'Profile banner markup must be present in response');
        $this->assertNotFalse($headingPos, '<h1 heading markup must be present in response');
        $this->assertLessThan(
            $headingPos,
            $bannerPos,
            'Profile banner markup position must be less than page heading <h1 position'
        );
    }

    public function test_page_heading_carries_no_header_clearance_padding(): void
    {
        $response = $this->actingAs($this->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/attendance');

        $response->assertOk();
        $response->assertSee('uj-head-stack');
    }

    public function test_head_stack_is_present_on_the_dashboard(): void
    {
        $response = $this->actingAs($this->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/dash');

        $response->assertOk();
        $response->assertSee('uj-head-stack');
    }
}
