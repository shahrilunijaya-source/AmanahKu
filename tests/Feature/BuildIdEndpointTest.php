<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The build fingerprint an open tab polls to learn a new release has landed. It sits
 * outside the tenant group on purpose, so it must answer JSON for any signed-in user
 * regardless of tenant state, and must not answer at all for a guest.
 */
class BuildIdEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_signed_in_user_gets_a_build_id(): void
    {
        $user = User::create(['name' => 'Sam', 'email' => 'sam@example.com', 'password' => Hash::make('password')]);

        $response = $this->actingAs($user)->getJson(route('build.id'));

        $response->assertOk();
        $this->assertNotEmpty($response->json('id'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_a_guest_is_turned_away(): void
    {
        $this->get(route('build.id'))->assertRedirect(route('login'));
    }
}
