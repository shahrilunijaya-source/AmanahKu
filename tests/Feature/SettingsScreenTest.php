<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FeatureManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The personal Settings screen (/app/security): one section at a time, picked by
 * ?section=, by a flash from the form that just posted, or by the 2FA policy. Plus the
 * password card, which is the only UI for Fortify's user-password.update route.
 */
class SettingsScreenTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create(['name' => 'Demo', 'email' => 'demo@example.com', 'password' => Hash::make('password')]);
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $this->user->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->user->id,
            'name' => 'Demo', 'status' => 'active', 'workload' => 'green',
        ]);
    }

    private function actingInTenant(): self
    {
        $this->actingAs($this->user)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    private function openSection(string $html): string
    {
        // A stray double quote in the x-data script ends the attribute early and dumps the rest as page text.
        $dom = new \DOMDocument;
        @$dom->loadHTML($html);
        $root = (new \DOMXPath($dom))->query('//div[@class="uj-set"]')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $root);
        $this->assertStringContainsString('scrollLeft', $root->getAttribute('x-data'));
        $this->assertMatchesRegularExpression("/section: '(\\w+)'/", $html);
        preg_match("/section: '(\\w+)'/", $html, $m);

        return $m[1];
    }

    public function test_opens_on_account_by_default(): void
    {
        $html = $this->actingInTenant()->get('/app/security')->assertOk()->getContent();

        $this->assertSame('account', $this->openSection($html));
        $this->assertStringContainsString('demo@example.com', $html);
        $this->assertStringContainsString('Update password', $html);
    }

    public function test_section_query_picks_the_panel(): void
    {
        foreach (['security', 'appearance', 'ai'] as $section) {
            $html = $this->actingInTenant()->get('/app/security?section='.$section)->assertOk()->getContent();
            $this->assertSame($section, $this->openSection($html));
        }
    }

    public function test_unknown_section_falls_back_to_account(): void
    {
        $html = $this->actingInTenant()->get('/app/security?section=nope')->assertOk()->getContent();

        $this->assertSame('account', $this->openSection($html));
    }

    public function test_required_2fa_opens_on_security_for_an_unenrolled_user(): void
    {
        app(FeatureManager::class)->setTenant($this->tenant, 'security.2fa', 'required');

        $html = $this->actingInTenant()->get('/app/security')->assertOk()->getContent();

        $this->assertSame('security', $this->openSection($html));
        $this->assertStringContainsString('Your workspace requires this', $html);
    }

    public function test_a_freshly_minted_ai_key_lands_on_the_ai_section(): void
    {
        $url = route('app.screen', 'security');
        $this->actingInTenant()->from($url)->post('/app/security/ai-key/generate', ['password' => 'password']);

        $html = $this->actingInTenant()->get($url)->assertOk()->getContent();

        $this->assertSame('ai', $this->openSection($html));
        $this->assertStringContainsString('Copy this key now', $html);
    }

    public function test_read_only_choice_mints_a_read_only_key(): void
    {
        $this->actingInTenant()->post('/app/security/ai-key/generate', ['password' => 'password', 'allow_writes' => '0'])
            ->assertSessionHasNoErrors();

        $this->assertSame(['timesheets:read', 'board:read', 'tot:read'], $this->user->tokens()->first()->abilities);
    }

    public function test_active_key_shows_what_it_may_do(): void
    {
        $this->actingInTenant()->post('/app/security/ai-key/generate', ['password' => 'password', 'allow_writes' => '1']);

        $html = $this->actingInTenant()->get('/app/security?section=ai')->assertOk()->getContent();

        $this->assertStringContainsString('Read and make changes', $html);
        $this->assertStringContainsString('Revoke key', $html);
    }

    public function test_password_can_be_changed(): void
    {
        $url = route('app.screen', ['screen' => 'security', 'section' => 'account']);

        $this->actingInTenant()->from($url)->put(route('user-password.update'), [
            'current_password' => 'password',
            'password' => 'NewPassw0rd99',
            'password_confirmation' => 'NewPassw0rd99',
        ])->assertRedirect($url)->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('NewPassw0rd99', $this->user->fresh()->password));

        $this->actingInTenant()->withSession(['status' => 'password-updated'])
            ->get($url)->assertOk()->assertSee('Password updated.');
    }

    public function test_wrong_current_password_shows_the_error_on_the_account_section(): void
    {
        $html = $this->actingInTenant()->from('/app/security?section=security')->followingRedirects()
            ->put(route('user-password.update'), [
                'current_password' => 'wrong',
                'password' => 'NewPassw0rd99',
                'password_confirmation' => 'NewPassw0rd99',
            ])->getContent();

        $this->assertTrue(Hash::check('password', $this->user->fresh()->password));
        $this->assertSame('account', $this->openSection($html));
        $this->assertStringContainsString('does not match your current password', $html);
    }

    public function test_wrong_two_factor_code_is_shown_on_the_security_section(): void
    {
        $this->actingInTenant()->withSession(['auth.password_confirmed_at' => time()])->post(route('two-factor.enable'));

        $html = $this->actingInTenant()->withSession(['auth.password_confirmed_at' => time()])->from('/app/security?section=account')->followingRedirects()
            ->post(route('two-factor.confirm'), ['code' => '000000'])->getContent();

        $this->assertSame('security', $this->openSection($html));
        $this->assertStringContainsString('two factor authentication code was invalid', $html);
    }

    public function test_account_menu_links_background_to_its_section(): void
    {
        $this->actingInTenant()->get('/app/dash')->assertOk()
            ->assertSee(route('app.screen', ['screen' => 'security', 'section' => 'appearance']), escape: false);
    }

    public function test_account_section_offers_the_birthday_privacy_switch_to_a_user_with_an_employee(): void
    {
        $html = $this->actingInTenant()->get('/app/security?section=account')->assertOk()->getContent();

        $this->assertStringContainsString('birthday_private', $html);
        $this->assertStringContainsString('Keep my birthday private', $html);
    }

    public function test_posting_the_birthday_privacy_switch_updates_only_the_posters_own_employee(): void
    {
        $other = User::create(['name' => 'Other', 'email' => 'other@example.com', 'password' => Hash::make('password')]);
        $other->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $otherEmployee = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $other->id,
            'name' => 'Other', 'status' => 'active', 'workload' => 'green',
        ]);

        $this->actingInTenant()->post('/app/security/birthday-privacy', ['birthday_private' => '1'])
            ->assertSessionHasNoErrors();

        $employee = Employee::where('user_id', $this->user->id)->firstOrFail();
        $this->assertTrue($employee->fresh()->birthday_private);
        $this->assertFalse($otherEmployee->fresh()->birthday_private);

        $this->actingInTenant()->post('/app/security/birthday-privacy', ['birthday_private' => '0'])
            ->assertSessionHasNoErrors();
        $this->assertFalse($employee->fresh()->birthday_private);
    }

    public function test_a_user_without_an_employee_neither_sees_nor_may_post_the_switch(): void
    {
        $solo = User::create(['name' => 'Solo', 'email' => 'solo@example.com', 'password' => Hash::make('password')]);
        $solo->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $this->actingAs($solo)->withSession(['current_tenant' => $this->tenant->id]);

        $this->get('/app/security?section=account')->assertOk()->assertDontSee('Keep my birthday private');
        $this->post('/app/security/birthday-privacy', ['birthday_private' => '1'])->assertNotFound();
    }
}
