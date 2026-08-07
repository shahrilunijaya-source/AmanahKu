<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The poll endpoint that backs the live header bell. Unlike notifications.unseen
 * (cursor-based, unread-only, feeds the OS-push notifier) this returns the same
 * mixed-read-state snapshot the header composer renders on first paint, so a poll
 * tick can fully replace the bell's state.
 */
class NotificationSummaryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        app(CurrentTenant::class)->set($this->tenant);
        $this->user = $this->member('Aina', 'aina@acme.test');
    }

    protected function tearDown(): void
    {
        app(CurrentTenant::class)->set(null);
        parent::tearDown();
    }

    private function member(string $name, string $email): User
    {
        $user = User::create(['name' => $name, 'email' => $email, 'password' => Hash::make('password')]);
        $user->tenants()->attach($this->tenant->id, ['role' => 'employee']);

        return $user;
    }

    public function test_returns_unread_count_and_recent_notifications(): void
    {
        AppNotification::create(['user_id' => $this->user->id, 'title' => 'Read one', 'read_at' => now()]);
        $unread = AppNotification::create(['user_id' => $this->user->id, 'title' => 'Unread one', 'body' => 'Body', 'url' => '/app']);

        $response = $this->actingAs($this->user)->withSession(['current_tenant' => $this->tenant->id])
            ->getJson(route('notifications.summary'));

        $response->assertOk()
            ->assertJsonPath('unread', 1)
            ->assertJsonCount(2, 'notifications')
            ->assertJsonPath('notifications.0.id', $unread->id)
            ->assertJsonPath('notifications.0.title', 'Unread one')
            ->assertJsonPath('notifications.0.body', 'Body')
            ->assertJsonPath('notifications.0.url', '/app')
            ->assertJsonPath('notifications.0.read_at', false)
            ->assertJsonPath('notifications.1.read_at', true);
    }

    public function test_caps_results_at_eight(): void
    {
        collect(range(1, 9))->each(
            fn (int $i) => AppNotification::create(['user_id' => $this->user->id, 'title' => "Bell {$i}"])
        );

        $this->actingAs($this->user)->withSession(['current_tenant' => $this->tenant->id])
            ->getJson(route('notifications.summary'))
            ->assertOk()
            ->assertJsonCount(8, 'notifications');
    }

    public function test_never_returns_another_users_notifications(): void
    {
        $other = $this->member('Bala', 'bala@acme.test');
        AppNotification::create(['user_id' => $other->id, 'title' => 'Not yours']);

        $this->actingAs($this->user)->withSession(['current_tenant' => $this->tenant->id])
            ->getJson(route('notifications.summary'))
            ->assertOk()
            ->assertJsonPath('unread', 0)
            ->assertJsonCount(0, 'notifications');
    }

    public function test_requires_authentication(): void
    {
        $this->getJson(route('notifications.summary'))->assertUnauthorized();
    }
}
