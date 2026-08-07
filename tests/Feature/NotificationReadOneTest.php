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
 * Clicking a single bell in the dropdown marks just that one read, separate from the
 * "Mark all read" button. Route-model binding is not tenant-scoped (SubstituteBindings
 * runs before ResolveTenant), so ownership must be checked explicitly in the controller.
 */
class NotificationReadOneTest extends TestCase
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

    public function test_marks_a_single_notification_read(): void
    {
        $notification = AppNotification::create(['user_id' => $this->user->id, 'title' => 'Bell']);

        $this->actingAs($this->user)->withSession(['current_tenant' => $this->tenant->id])
            ->postJson(route('notifications.read-one', $notification))
            ->assertOk();

        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_leaves_other_notifications_untouched(): void
    {
        $notification = AppNotification::create(['user_id' => $this->user->id, 'title' => 'Bell']);
        $other = AppNotification::create(['user_id' => $this->user->id, 'title' => 'Other']);

        $this->actingAs($this->user)->withSession(['current_tenant' => $this->tenant->id])
            ->postJson(route('notifications.read-one', $notification))
            ->assertOk();

        $this->assertNull($other->fresh()->read_at);
    }

    public function test_cannot_mark_another_users_notification_read(): void
    {
        $other = $this->member('Bala', 'bala@acme.test');
        $notification = AppNotification::create(['user_id' => $other->id, 'title' => 'Not yours']);

        $this->actingAs($this->user)->withSession(['current_tenant' => $this->tenant->id])
            ->postJson(route('notifications.read-one', $notification))
            ->assertForbidden();

        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_cannot_mark_another_tenants_notification_read(): void
    {
        $otherTenant = Tenant::create(['slug' => 'other', 'name' => 'Other', 'initials' => 'OT']);
        app(CurrentTenant::class)->set($otherTenant);
        $notification = AppNotification::create(['user_id' => $this->user->id, 'title' => 'Wrong tenant']);

        // Reset before the request: a real request starts with no tenant bound yet
        // (SubstituteBindings runs before ResolveTenant sets it from the session), so
        // route-model binding must resolve across tenants — otherwise this test would
        // 404 at the binding instead of exercising the controller's explicit check.
        app(CurrentTenant::class)->set(null);

        $this->actingAs($this->user)->withSession(['current_tenant' => $this->tenant->id])
            ->postJson(route('notifications.read-one', $notification))
            ->assertForbidden();

        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_requires_authentication(): void
    {
        $notification = AppNotification::create(['user_id' => $this->user->id, 'title' => 'Bell']);

        $this->postJson(route('notifications.read-one', $notification))->assertUnauthorized();
    }
}
