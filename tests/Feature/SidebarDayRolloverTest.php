<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The sidebar's TODAY widget is server-rendered, so a tab left open overnight keeps
 * showing yesterday (CR-12). The layout stamps the day it was rendered on; the browser
 * reloads once it sees a later day. This pins the stamp and the timezone it is read in.
 */
class SidebarDayRolloverTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_shell_stamps_the_rendered_day_in_the_app_timezone(): void
    {
        Carbon::setTestNow('2026-09-05 23:30:00');
        $this->seed(DatabaseSeeder::class);
        $tenant = Tenant::where('slug', 'unijaya')->firstOrFail();
        $user = User::create(['name' => 'Yati', 'email' => 'yati@example.com', 'password' => Hash::make('password')]);
        $user->tenants()->attach($tenant->id, ['role' => 'manager']);

        $response = $this->actingAs($user)->withSession(['current_tenant' => $tenant->id])->get('/app/dash');

        $response->assertOk()
            ->assertSee("const renderedDay = '2026-09-05'", false)
            ->assertSee('timeZone: tz', false)
            ->assertSee("const tz = 'Asia\\/Kuala_Lumpur'", false);

        Carbon::setTestNow();
    }
}
