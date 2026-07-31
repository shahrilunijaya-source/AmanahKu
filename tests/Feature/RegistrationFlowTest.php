<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Self-serve registration + email verification + no-workspace landing (Phase 2).
 */
class RegistrationFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_page_loads(): void
    {
        $this->get('/register')->assertOk()->assertSee('Create your account');
    }

    public function test_a_visitor_can_register_and_is_unverified(): void
    {
        Event::fake();

        $response = $this->post('/register', [
            'name' => 'New Person',
            'email' => 'new@example.com',
            'password' => 'Sup3r-Secret-Pw!',
            'password_confirmation' => 'Sup3r-Secret-Pw!',
        ]);

        $response->assertRedirect('/tenant');

        $user = User::where('email', 'new@example.com')->firstOrFail();
        $this->assertNull($user->email_verified_at);
        $this->assertFalse($user->isSuperAdmin());
        $this->assertCount(0, $user->tenants);

        // MustVerifyEmail → Fortify fires Registered, which triggers the verification mail.
        Event::assertDispatched(Registered::class);
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
