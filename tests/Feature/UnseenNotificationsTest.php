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
 * The poll endpoint that backs the browser notification. It must never leak another
 * user's bells, and must respect the client's cursor so a returning tab is not spammed.
 */
class UnseenNotificationsTest extends TestCase
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

    public function test_returns_unread_notifications_newer_than_the_cursor(): void
    {
        $old = AppNotification::create(['user_id' => $this->user->id, 'title' => 'Old']);
        $new = AppNotification::create(['user_id' => $this->user->id, 'title' => 'New', 'body' => 'Body', 'url' => '/app']);

        $response = $this->actingAs($this->user)->withSession(['current_tenant' => $this->tenant->id])
            ->getJson(route('notifications.unseen', ['since' => $old->id]));

        $response->assertOk()
            ->assertJsonPath('latestId', $new->id)
            ->assertJsonCount(1, 'notifications')
            ->assertJsonPath('notifications.0.id', $new->id)
            ->assertJsonPath('notifications.0.title', 'New')
            ->assertJsonPath('notifications.0.body', 'Body')
            ->assertJsonPath('notifications.0.url', '/app');
    }

    public function test_excludes_already_read_notifications(): void
    {
        AppNotification::create(['user_id' => $this->user->id, 'title' => 'Read', 'read_at' => now()]);

        $this->actingAs($this->user)->withSession(['current_tenant' => $this->tenant->id])
            ->getJson(route('notifications.unseen'))
            ->assertOk()
            ->assertJsonCount(0, 'notifications');
    }

    public function test_never_returns_another_users_notifications(): void
    {
        $other = $this->member('Bala', 'bala@acme.test');
        AppNotification::create(['user_id' => $other->id, 'title' => 'Not yours']);

        $this->actingAs($this->user)->withSession(['current_tenant' => $this->tenant->id])
            ->getJson(route('notifications.unseen'))
            ->assertOk()
            ->assertJsonCount(0, 'notifications')
            ->assertJsonPath('latestId', 0);
    }

    public function test_requires_authentication(): void
    {
        $this->getJson(route('notifications.unseen'))->assertUnauthorized();
    }

    /**
     * Guards the limit(5) cap: a long-idle tab with a backlog of unread bells must only
     * ever surface the 5 newest, never the full backlog in one burst.
     */
    public function test_caps_results_at_five_and_excludes_the_oldest(): void
    {
        $notifications = collect(range(1, 6))
            ->map(fn (int $i) => AppNotification::create(['user_id' => $this->user->id, 'title' => "Bell {$i}"]));

        $oldest = $notifications->first();

        $response = $this->actingAs($this->user)->withSession(['current_tenant' => $this->tenant->id])
            ->getJson(route('notifications.unseen'));

        $response->assertOk()->assertJsonCount(5, 'notifications');

        $returnedIds = collect($response->json('notifications'))->pluck('id');
        $this->assertFalse($returnedIds->contains($oldest->id));
    }
}
