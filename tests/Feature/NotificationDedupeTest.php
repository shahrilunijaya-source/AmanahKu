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
 * A dedupe key makes AppNotification::send idempotent, so a command that runs on a
 * short cadence can call it every tick without stacking duplicate bells.
 */
class NotificationDedupeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        app(CurrentTenant::class)->set($tenant);
        $this->user = User::create([
            'name' => 'Aina',
            'email' => 'aina@acme.test',
            'password' => Hash::make('password'),
        ]);
    }

    protected function tearDown(): void
    {
        app(CurrentTenant::class)->set(null);
        parent::tearDown();
    }

    public function test_same_dedupe_key_only_creates_one_row(): void
    {
        $first = AppNotification::send($this->user->id, 'Clock-in reminder', 'Body', '/app', 'attendance-in-2026-07-25');
        $second = AppNotification::send($this->user->id, 'Clock-in reminder', 'Body', '/app', 'attendance-in-2026-07-25');

        $this->assertTrue($first);
        $this->assertFalse($second);
        $this->assertSame(1, AppNotification::where('user_id', $this->user->id)->count());
    }

    public function test_different_dedupe_keys_create_separate_rows(): void
    {
        AppNotification::send($this->user->id, 'Clock-in reminder', null, null, 'attendance-in-2026-07-25');
        AppNotification::send($this->user->id, 'Clock-out reminder', null, null, 'attendance-out-2026-07-25');

        $this->assertSame(2, AppNotification::where('user_id', $this->user->id)->count());
    }

    public function test_notifications_without_a_key_are_never_deduped(): void
    {
        AppNotification::send($this->user->id, 'Claim approved');
        AppNotification::send($this->user->id, 'Claim approved');

        $this->assertSame(2, AppNotification::where('user_id', $this->user->id)->count());
    }
}
