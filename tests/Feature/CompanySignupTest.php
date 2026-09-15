<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CompanyCategory;
use App\Models\CompanyInvite;
use App\Models\PayrollItem;
use App\Models\Tenant;
use App\Models\TimesheetCategory;
use App\Models\User;
use App\Services\FeatureManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
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

    // ── POST /register ───────────────────────────────────────────

    /** @return array<string, string> */
    private function guestPayload(CompanyInvite $invite, array $overrides = []): array
    {
        return array_merge([
            'invite' => $invite->token,
            'company_name' => 'Maju Bina Sdn Bhd',
            'name' => 'Faizal bin Ahmad',
            'email' => 'faizal@majubina.com',
            'password' => 'Sup3r-Secret-Pw!',
            'password_confirmation' => 'Sup3r-Secret-Pw!',
        ], $overrides);
    }

    public function test_guest_with_a_valid_token_creates_the_company_and_lands_in_launch_center(): void
    {
        Notification::fake();
        $invite = CompanyInvite::factory()->create(['company_category_id' => CompanyCategory::where('level', 2)->value('id')]);

        $response = $this->post('/register', $this->guestPayload($invite));

        $response->assertRedirect(route('app.screen', 'setup'));

        $tenant = Tenant::where('name', 'Maju Bina Sdn Bhd')->firstOrFail();
        $this->assertSame('maju-bina-sdn-bhd', $tenant->slug);
        $this->assertSame('MB', $tenant->initials);
        $this->assertSame('active', $tenant->status);
        $this->assertTrue($tenant->onboarding_enforced);
        $this->assertSame(2, $tenant->categoryLevel());

        $this->assertDatabaseHas('branches', ['tenant_id' => $tenant->id, 'name' => 'HQ']);
        $this->assertDatabaseHas('departments', ['tenant_id' => $tenant->id, 'name' => 'General']);

        $user = User::where('email', 'faizal@majubina.com')->firstOrFail();
        $this->assertTrue(Hash::check('Sup3r-Secret-Pw!', $user->password));
        $this->assertFalse($user->password_change_required);
        $this->assertNotNull($user->email_verified_at);
        $this->assertFalse($user->isSuperAdmin());
        $this->assertSame('hr', $user->roleIn($tenant));
        $this->assertDatabaseHas('employees', [
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'position' => 'HR Admin', 'status' => 'active',
        ]);

        // Same seeds as the super-admin path.
        $this->assertSame(count(PayrollItem::SYSTEM_ITEMS), PayrollItem::where('tenant_id', $tenant->id)->count());
        $this->assertSame(
            count(TimesheetCategory::DEFAULTS),
            TimesheetCategory::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->count()
        );
        $this->assertDatabaseHas('greeting_lines', ['tenant_id' => $tenant->id]);
        $this->assertDatabaseHas('easter_eggs', ['tenant_id' => $tenant->id]);
        $this->assertTrue($tenant->featureEnabled('module.leave'));

        // Invite is spent, audit row written, nobody emailed (they chose their password).
        $invite->refresh();
        $this->assertNotNull($invite->used_at);
        $this->assertSame($tenant->id, $invite->used_by_tenant_id);
        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'actor_name' => 'Faizal bin Ahmad',
            'action' => 'Company self-registered', 'target' => 'Maju Bina Sdn Bhd · admin faizal@majubina.com',
        ]);
        Notification::assertNothingSent();

        // Logged in, inside the new company.
        $this->assertAuthenticatedAs($user);
        $response->assertSessionHas('current_tenant', $tenant->id);
        $response->assertSessionHas('persona', 'hr');
        $response->assertSessionMissing('url.intended');
    }

    public function test_after_signup_launch_center_actually_renders(): void
    {
        $invite = CompanyInvite::factory()->create();

        $this->post('/register', $this->guestPayload($invite));

        $this->get('/app/setup')->assertOk();
    }

    public function test_company_name_collision_gets_a_numeric_suffix(): void
    {
        Tenant::create(['slug' => 'maju-bina-sdn-bhd', 'name' => 'Maju Bina Sdn Bhd', 'initials' => 'MB']);
        $invite = CompanyInvite::factory()->create();

        $this->post('/register', $this->guestPayload($invite));

        $this->assertDatabaseHas('tenants', ['slug' => 'maju-bina-sdn-bhd-2', 'name' => 'Maju Bina Sdn Bhd']);
    }

    public function test_guest_with_an_existing_email_is_told_to_sign_in_and_nothing_is_created(): void
    {
        User::create(['name' => 'Taken', 'email' => 'faizal@majubina.com', 'password' => Hash::make('password')]);
        $invite = CompanyInvite::factory()->create();

        $response = $this->from('/register?invite='.$invite->token)
            ->post('/register', $this->guestPayload($invite));

        $response->assertRedirect('/register?invite='.$invite->token)
            ->assertSessionHasErrors(['email' => 'That email already has an account. Sign in first, then open this link again.']);

        $this->assertSame(0, Tenant::count());
        $this->assertNull($invite->fresh()->used_at);
        $this->assertGuest();

        // The re-rendered form carries the token and the sign-in nudge.
        $this->get('/register?invite='.$invite->token)
            ->assertOk()
            ->assertSee('Sign in')
            ->assertSee('name="invite" value="'.$invite->token.'"', false);
    }

    public function test_signed_in_user_is_attached_as_hr_without_a_new_user_row(): void
    {
        $mei = $this->memberOfAcme();
        $invite = CompanyInvite::factory()->create();
        $usersBefore = User::count();

        $response = $this->actingAs($mei)->post('/register', [
            'invite' => $invite->token,
            'company_name' => 'Second Venture',
        ]);

        $response->assertRedirect(route('app.screen', 'setup'));

        $tenant = Tenant::where('name', 'Second Venture')->firstOrFail();
        $this->assertSame($usersBefore, User::count());
        $this->assertSame('hr', $mei->fresh()->roleIn($tenant));
        $this->assertSame('employee', $mei->fresh()->roleIn(Tenant::where('slug', 'acme')->firstOrFail()));
        $this->assertDatabaseHas('employees', ['tenant_id' => $tenant->id, 'user_id' => $mei->id, 'position' => 'HR Admin']);
        $response->assertSessionHas('current_tenant', $tenant->id);
        $this->assertSame($tenant->id, $invite->fresh()->used_by_tenant_id);
    }

    public function test_signed_in_user_submitting_name_and_password_fields_has_them_ignored(): void
    {
        $mei = $this->memberOfAcme();
        $invite = CompanyInvite::factory()->create();

        $this->actingAs($mei)->post('/register', $this->guestPayload($invite, ['company_name' => 'Second Venture']))
            ->assertRedirect(route('app.screen', 'setup'));

        $this->assertSame(0, User::where('email', 'faizal@majubina.com')->count());
        $this->assertSame('Mei Ling', $mei->fresh()->name);
    }

    public function test_super_admin_post_is_refused(): void
    {
        $invite = CompanyInvite::factory()->create();

        $this->actingAs($this->superAdmin())
            ->post('/register', ['invite' => $invite->token, 'company_name' => 'Nope'])
            ->assertForbidden();

        $this->assertSame(0, Tenant::count());
    }

    public function test_unknown_token_on_post_404s(): void
    {
        $this->post('/register', $this->guestPayload(CompanyInvite::factory()->create(), ['invite' => str_repeat('x', 40)]))->assertNotFound();
        $this->post('/register', ['company_name' => 'x'])->assertNotFound();
    }

    public function test_second_submit_with_the_same_token_fails(): void
    {
        $invite = CompanyInvite::factory()->create();
        $this->post('/register', $this->guestPayload($invite))->assertRedirect(route('app.screen', 'setup'));
        $this->post('/logout');
        $this->assertGuest();

        $this->post('/register', $this->guestPayload($invite, ['email' => 'second@majubina.com', 'company_name' => 'Copycat']))
            ->assertSessionHasErrors(['invite' => 'This link has already been used.']);

        $this->assertSame(1, Tenant::count());
        $this->assertSame(0, User::where('email', 'second@majubina.com')->count());
    }

    public function test_expired_token_on_post_is_rejected(): void
    {
        $invite = CompanyInvite::factory()->expired()->create();

        $this->post('/register', $this->guestPayload($invite))
            ->assertSessionHasErrors(['invite' => 'This link has expired.']);

        $this->assertSame(0, Tenant::count());
    }

    public function test_validation_failure_keeps_the_token_and_creates_nothing(): void
    {
        $invite = CompanyInvite::factory()->create();

        $this->from('/register?invite='.$invite->token)
            ->post('/register', $this->guestPayload($invite, ['password_confirmation' => 'different']))
            ->assertRedirect('/register?invite='.$invite->token)
            ->assertSessionHasErrors('password');

        $this->assertSame(0, Tenant::count());
        $this->assertSame(0, User::where('email', 'faizal@majubina.com')->count());
        $this->assertNull($invite->fresh()->used_at);
    }

    public function test_registration_switch_off_blocks_the_post(): void
    {
        app(FeatureManager::class)->setPlatform('platform.registration', false, false);
        $invite = CompanyInvite::factory()->create();

        $this->post('/register', $this->guestPayload($invite))->assertRedirect('/login');

        $this->assertSame(0, Tenant::count());
        $this->assertNull($invite->fresh()->used_at);
    }
}
