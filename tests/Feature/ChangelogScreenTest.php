<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Support\Changelog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ChangelogScreenTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

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
    }

    public function test_any_authenticated_user_can_open_the_changelog_screen(): void
    {
        $response = $this->actingAs($this->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/changelog');

        $response->assertOk();
        $response->assertViewHas('releases');
        $response->assertSee('Changelog');
    }

    public function test_it_lists_every_release_with_its_tagged_entries(): void
    {
        $response = $this->actingAs($this->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/changelog');

        $response->assertOk();
        $response->assertSee('1.1');
        $response->assertSee('New Changelog screen', false);
        $response->assertSee('Timesheet add-entry now opens in one popup', false);
        $response->assertSee('Added');
        $response->assertSee('Improved');
        // 'added' carries the success tone; 'improved' carries no data-tone (neutral default).
        // Derived from the data rather than hardcoded, so a new release doesn't need this test touched.
        $expectedAddedCount = collect(Changelog::releases())
            ->flatMap(fn (array $release) => $release['entries'])
            ->filter(fn (array $entry) => $entry['tag'] === 'added')
            ->count();
        $this->assertSame(
            $expectedAddedCount,
            substr_count($response->getContent(), 'uj-stamp" data-tone="success"'),
            'Every added-tagged entry must render with the success tone.'
        );
    }

    public function test_the_sidebar_footer_links_to_the_changelog_from_any_screen(): void
    {
        $response = $this->actingAs($this->user)
            ->withSession(['current_tenant' => $this->tenant->id])
            ->get('/app/dash');

        $response->assertOk();
        $response->assertSee(route('app.screen', 'changelog'), false);
    }
}
