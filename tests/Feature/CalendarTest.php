<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Feature coverage for the read-only time-off calendar.
 *
 * Runs the full DatabaseSeeder, signs in as the seeded HR user
 * (aisyah.rahman@unijaya.example) and enters the Unijaya tenant. Seed contains
 * approved leave for Siti Khadijah 23–27 Jun 2026 and a Hari Raya Aidiladha
 * holiday on 27 Jun 2026 — both fall in the default (current) month.
 */
class CalendarTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        // Pin "now" to June 2026 so the current-month assertions are deterministic
        // regardless of suite order or another test leaking Carbon::setTestNow().
        Carbon::setTestNow('2026-06-24');
        CarbonImmutable::setTestNow('2026-06-24');

        $this->seed(DatabaseSeeder::class);

        $this->user = User::where('email', 'aisyah.rahman@unijaya.example')->firstOrFail();
        $this->tenant = Tenant::where('slug', 'unijaya')->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    private function actingInTenant(): self
    {
        $this->actingAs($this->user)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    public function test_calendar_renders_current_month_with_seeded_time_off(): void
    {
        // Act
        $response = $this->actingInTenant()->get('/app/calendar');

        // Assert — current month is June 2026 (app "now"); seeded items appear.
        $response->assertOk();
        $response->assertSee('June 2026');
        $response->assertSee('Hari Raya Aidiladha');
        $response->assertSee('Siti Khadijah');
    }

    public function test_next_month_navigation_returns_ok(): void
    {
        // Act
        $response = $this->actingInTenant()->get('/app/calendar?month=2026-07');

        // Assert
        $response->assertOk();
        $response->assertSee('July 2026');
    }

    public function test_malformed_month_falls_back_to_current_month(): void
    {
        // Act — invalid month value should not error.
        $response = $this->actingInTenant()->get('/app/calendar?month=2026-13');

        // Assert
        $response->assertOk();
        $response->assertSee('June 2026');
    }
}
