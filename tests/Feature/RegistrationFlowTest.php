<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * No-workspace landing for an account with no company, and the fact that /register
 * is no longer an open door (invite-link signup lives in CompanySignupTest).
 */
class RegistrationFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_page_without_an_invite_is_a_404(): void
    {
        $this->get('/register')->assertNotFound();
    }

    public function test_registered_user_with_no_company_sees_no_workspace_state(): void
    {
        $user = User::create([
            'name' => 'Orphan', 'email' => 'orphan@example.com', 'password' => Hash::make('password'),
        ]);

        $response = $this->actingAs($user)->get('/tenant');

        $response->assertOk()
            ->assertSee('No workspace yet')
            ->assertSee('verify your email');
    }

    public function test_verified_user_with_a_company_does_not_see_no_workspace_state(): void
    {
        $this->seed(DatabaseSeeder::class);
        $user = User::where('email', 'aisyah.rahman@unijaya.example')->firstOrFail();

        $this->assertNotNull($user->email_verified_at);

        $response = $this->actingAs($user)->get('/tenant');
        $response->assertOk()->assertDontSee('No workspace yet');
    }
}
