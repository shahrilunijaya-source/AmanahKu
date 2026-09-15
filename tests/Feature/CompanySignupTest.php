<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CompanyInvite;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FeatureManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Invite-link company signup: /register?invite=TOKEN. The token is the only way in.
 */
class CompanySignupTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        $u = User::create(['name' => 'Platform', 'email' => 'super@example.com', 'password' => Hash::make('password')]);
        $u->forceFill(['is_super_admin' => true])->save();

        return $u;
    }

    private function memberOfAcme(): User
    {
        $u = User::create(['name' => 'Mei Ling', 'email' => 'mei@example.com', 'password' => Hash::make('password')]);
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $u->tenants()->attach($tenant->id, ['role' => 'employee']);

        return $u;
    }

    // ── GET /register ────────────────────────────────────────────

    public function test_valid_token_shows_the_signup_form(): void
    {
        $invite = CompanyInvite::factory()->create();

        $this->get('/register?invite='.$invite->token)
            ->assertOk()
            ->assertSee('Set up your company')
            ->assertSee('name="company_name"', false)
            ->assertSee('name="name"', false)
            ->assertSee('name="email"', false)
            ->assertSee('name="password"', false)
            ->assertSee('name="invite" value="'.$invite->token.'"', false)
            ->assertSessionHas('url.intended', url('/register?invite='.$invite->token));
    }

    public function test_missing_and_unknown_tokens_404(): void
    {
        $this->get('/register')->assertNotFound();
        $this->get('/register?invite=')->assertNotFound();
        $this->get('/register?invite='.str_repeat('x', 40))->assertNotFound();
    }

    public function test_used_token_404s_with_an_explanation(): void
    {
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $invite = CompanyInvite::factory()->usedBy($tenant)->create();

        $this->get('/register?invite='.$invite->token)
            ->assertNotFound()
            ->assertSee('This link has already been used');
    }

    public function test_expired_token_404s_with_an_explanation(): void
    {
        $invite = CompanyInvite::factory()->expired()->create();

        $this->get('/register?invite='.$invite->token)
            ->assertNotFound()
            ->assertSee('This link has expired');
    }

    public function test_registration_switch_off_blocks_the_page_but_keeps_the_invite(): void
    {
        app(FeatureManager::class)->setPlatform('platform.registration', false, false);
        $invite = CompanyInvite::factory()->create();

        $this->get('/register?invite='.$invite->token)->assertRedirect('/login');

        $this->assertDatabaseHas('company_invites', ['id' => $invite->id, 'used_at' => null]);
    }

    public function test_super_admin_is_refused(): void
    {
        $invite = CompanyInvite::factory()->create();

        $this->actingAs($this->superAdmin())
            ->get('/register?invite='.$invite->token)
            ->assertForbidden()
            ->assertSee('You already see every company');
    }

    public function test_signed_in_user_sees_only_the_company_name_field(): void
    {
        $invite = CompanyInvite::factory()->create();

        $this->actingAs($this->memberOfAcme())
            ->get('/register?invite='.$invite->token)
            ->assertOk()
            ->assertSee('name="company_name"', false)
            ->assertSee('Mei Ling')
            ->assertDontSee('name="password"', false)
            ->assertDontSee('name="email"', false);
    }
}
