<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CompanyCategory;
use App\Models\CompanyInvite;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Features;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Super-admin side of invite-link signup: generate, list, revoke. Non-super-admins
 * never see any of it.
 */
class SuperAdminCompanyInviteTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        $u = User::create(['name' => 'Platform', 'email' => 'super@example.com', 'password' => Hash::make('password')]);
        $u->forceFill(['is_super_admin' => true])->save();

        return $u;
    }

    private function ordinaryUser(): User
    {
        $u = User::create(['name' => 'Joe', 'email' => 'joe@example.com', 'password' => Hash::make('password')]);
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $u->tenants()->attach($tenant->id, ['role' => 'hr']);

        return $u;
    }

    public function test_super_admin_generates_a_link_and_sees_it_flashed(): void
    {
        $admin = $this->superAdmin();
        $stage2 = CompanyCategory::where('level', 2)->firstOrFail();

        $response = $this->actingAs($admin)->post('/admin/invites', [
            'note' => 'Encik Faizal, Maju Bina Sdn Bhd',
            'company_category_id' => $stage2->id,
        ]);

        $response->assertRedirect(route('superadmin.companies.index'));

        $invite = CompanyInvite::firstOrFail();
        $this->assertSame(40, strlen($invite->token));
        $this->assertSame('Encik Faizal, Maju Bina Sdn Bhd', $invite->note);
        $this->assertSame($stage2->id, $invite->company_category_id);
        $this->assertSame($admin->id, $invite->created_by_user_id);
        $this->assertNull($invite->used_at);
        $this->assertTrue($invite->expires_at->between(now()->addDays(7)->subMinute(), now()->addDays(7)->addMinute()));

        $response->assertSessionHas('inviteUrl', $invite->url());
        $this->assertStringContainsString('/register?invite='.$invite->token, $invite->url());
    }

    public function test_generate_requires_a_real_category(): void
    {
        $this->actingAs($this->superAdmin())
            ->post('/admin/invites', ['note' => 'x', 'company_category_id' => 999])
            ->assertSessionHasErrors('company_category_id');

        $this->assertSame(0, CompanyInvite::count());
    }

    public function test_index_lists_pending_used_and_expired_invites(): void
    {
        $tenant = Tenant::create(['slug' => 'zr-catering', 'name' => 'ZR Catering', 'initials' => 'ZC']);
        $pending = CompanyInvite::factory()->create(['note' => 'Pending person']);
        CompanyInvite::factory()->usedBy($tenant)->create(['note' => 'Used person']);
        CompanyInvite::factory()->expired()->create(['note' => 'Expired person']);

        $response = $this->actingAs($this->superAdmin())->get('/admin/companies');

        $response->assertOk()
            ->assertSee('Signup links')
            ->assertSee('Pending person')
            ->assertSee('Used person')
            ->assertSee('Expired person')
            ->assertSee('Used · ZR Catering')
            ->assertSee('Expired')
            ->assertSee(route('superadmin.companies.show', $tenant))
            ->assertSee($pending->token);
    }

    /** Once the company behind a used link is deleted, the link is an orphan: still shown, but named as one. */
    public function test_index_shows_company_deleted_and_a_remove_button_for_an_orphaned_used_invite(): void
    {
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $invite = CompanyInvite::factory()->usedBy($tenant)->create(['note' => 'Orphan person']);
        $tenant->delete();

        $this->actingAs($this->superAdmin())
            ->get('/admin/companies')
            ->assertOk()
            ->assertSee('Orphan person')
            ->assertSee('Used · Company deleted')
            ->assertSee('action="'.route('superadmin.invites.destroy', $invite).'"', false);
    }

    public function test_super_admin_revokes_a_pending_invite(): void
    {
        $invite = CompanyInvite::factory()->create();

        $this->actingAs($this->superAdmin())
            ->post("/admin/invites/{$invite->id}/delete")
            ->assertRedirect(route('superadmin.companies.index'));

        $this->assertDatabaseMissing('company_invites', ['id' => $invite->id]);
    }

    public function test_super_admin_can_remove_an_expired_invite(): void
    {
        $invite = CompanyInvite::factory()->expired()->create();

        $this->actingAs($this->superAdmin())->post("/admin/invites/{$invite->id}/delete");

        $this->assertDatabaseMissing('company_invites', ['id' => $invite->id]);
    }

    public function test_used_invite_cannot_be_revoked(): void
    {
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $invite = CompanyInvite::factory()->usedBy($tenant)->create();

        $this->actingAs($this->superAdmin())
            ->post("/admin/invites/{$invite->id}/delete")
            ->assertForbidden();

        $this->assertDatabaseHas('company_invites', ['id' => $invite->id]);
    }

    /** Once the company is gone, the link it was used for is just clutter: it can be removed. */
    public function test_an_orphaned_used_invite_can_be_removed(): void
    {
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $invite = CompanyInvite::factory()->usedBy($tenant)->create();
        $tenant->delete();

        $this->actingAs($this->superAdmin())
            ->post("/admin/invites/{$invite->id}/delete")
            ->assertRedirect(route('superadmin.companies.index'));

        $this->assertDatabaseMissing('company_invites', ['id' => $invite->id]);
    }

    public function test_ordinary_user_gets_403_on_every_invite_route(): void
    {
        $user = $this->ordinaryUser();
        $invite = CompanyInvite::factory()->create();

        $this->actingAs($user)->post('/admin/invites', [
            'company_category_id' => CompanyCategory::where('level', 2)->value('id'),
        ])->assertForbidden();
        $this->actingAs($user)->post("/admin/invites/{$invite->id}/delete")->assertForbidden();

        $this->assertSame(1, CompanyInvite::count());
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->post('/admin/invites', ['company_category_id' => 1])->assertRedirect('/login');
    }

    /**
     * The copy buttons used to put @js() output (single-quoted) inside a single-quoted
     * onclick attribute, which ended the attribute early and made the button dead.
     * The URL now travels in a data attribute and a shared helper copies it.
     */
    public function test_copy_buttons_carry_the_link_in_a_data_attribute(): void
    {
        $pending = CompanyInvite::factory()->create(['note' => 'Pending person']);
        $admin = $this->superAdmin();

        $this->actingAs($admin)
            ->get('/admin/companies')
            ->assertOk()
            ->assertSee('data-copy="'.e($pending->url()).'"', false)
            ->assertSee('function ujCopy', false)
            ->assertDontSee("onclick='navigator.clipboard", false);

        $this->actingAs($admin)
            ->withSession(['inviteUrl' => 'http://example.test/register?invite=abc'])
            ->get('/admin/companies')
            ->assertSee('data-copy="http://example.test/register?invite=abc"', false);
    }

    /** The switch that closes signup names what it does now: signup links, not open registration. */
    public function test_the_signup_switch_is_labelled_for_signup_links(): void
    {
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);

        $this->actingAs($this->superAdmin())
            ->get(route('superadmin.companies.features', $tenant))
            ->assertOk()
            ->assertSee('Signup links')
            ->assertDontSee('Public self-registration');

        $this->assertStringContainsString('Off closes the signup page', Features::SETTINGS['platform.registration']['help']);
    }
}
