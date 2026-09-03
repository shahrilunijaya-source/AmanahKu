<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Profile cover photo (employees.cover_path): who may set or remove it, the tenant
 * guard, and what the profile screen renders. Harness copied from ProfileVisibilityTest.
 * Spec: docs/superpowers/specs/2026-09-03-custom-backgrounds-design.html
 */
class ProfileCoverTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
    }

    /** An employee with a login in the given role. Does not sign anyone in. */
    private function person(string $role, ?Tenant $tenant = null): Employee
    {
        $this->seq++;
        $tenant ??= $this->tenant;
        $user = User::create(['name' => $role.$this->seq, 'email' => "{$role}{$this->seq}@example.com", 'password' => Hash::make('password')]);
        $user->tenants()->attach($tenant->id, ['role' => $role]);

        return Employee::create([
            'tenant_id' => $tenant->id, 'user_id' => $user->id,
            'name' => ucfirst($role).' '.$this->seq, 'status' => 'active', 'workload' => 'green',
        ]);
    }

    private function signIn(Employee $e): self
    {
        $this->actingAs($e->user)->withSession(['current_tenant' => $e->tenant_id]);

        return $this;
    }

    public function test_owner_uploads_a_cover_and_it_replaces_the_previous_file(): void
    {
        $me = $this->person('employee');
        Storage::disk('public')->put("covers/{$me->id}/old.jpg", 'x');
        $me->update(['cover_path' => "covers/{$me->id}/old.jpg"]);

        $this->signIn($me)->post(route('employees.cover.update', $me), ['photo' => UploadedFile::fake()->image('c.jpg', 1600, 600)])
            ->assertRedirect();

        $path = $me->fresh()->cover_path;
        $this->assertStringStartsWith("covers/{$me->id}/", $path);
        Storage::disk('public')->assertExists($path);
        Storage::disk('public')->assertMissing("covers/{$me->id}/old.jpg");
        $this->assertNotNull($me->fresh()->cover_luminance);
    }

    public function test_a_dark_cover_turns_the_title_row_white_and_a_light_one_does_not(): void
    {
        $me = $this->person('employee');

        $me->update(['cover_path' => 'preset:slate']);
        $html = $this->signIn($me)->get(route('app.screen', ['screen' => 'profile', 'emp' => $me->id]))->getContent();
        $this->assertStringContainsString('uj-has-cover uj-cover-dark', $html);

        $me->update(['cover_path' => 'preset:sand']);
        $html = $this->signIn($me)->get(route('app.screen', ['screen' => 'profile', 'emp' => $me->id]))->getContent();
        $this->assertStringContainsString('uj-has-cover', $html);
        $this->assertStringNotContainsString('uj-cover-dark', $html);

        $me->update(['cover_path' => "covers/{$me->id}/night.jpg", 'cover_luminance' => 0.05]);
        $html = $this->signIn($me)->get(route('app.screen', ['screen' => 'profile', 'emp' => $me->id]))->getContent();
        $this->assertStringContainsString('uj-cover-dark', $html);

        $this->assertTrue($me->fresh()->coverIsDark());
        $me->update(['cover_path' => 'preset:slate', 'cover_luminance' => null]);
        $this->assertTrue($me->fresh()->coverIsDark());
    }

    public function test_a_colleague_cannot_upload_someone_elses_cover(): void
    {
        $me = $this->person('employee');
        $them = $this->person('employee');

        $this->signIn($me)->post(route('employees.cover.update', $them), ['photo' => UploadedFile::fake()->image('c.jpg')])
            ->assertForbidden();
        $this->assertNull($them->fresh()->cover_path);
    }

    public function test_hr_cannot_upload_for_someone_else_but_can_remove(): void
    {
        $hr = $this->person('hr');
        $them = $this->person('employee');
        Storage::disk('public')->put("covers/{$them->id}/a.jpg", 'x');
        $them->update(['cover_path' => "covers/{$them->id}/a.jpg"]);

        $this->signIn($hr)->post(route('employees.cover.update', $them), ['photo' => UploadedFile::fake()->image('c.jpg')])->assertForbidden();

        $this->signIn($hr)->post(route('employees.cover.destroy', $them))->assertRedirect();
        $this->assertNull($them->fresh()->cover_path);
        Storage::disk('public')->assertMissing("covers/{$them->id}/a.jpg");
    }

    public function test_director_cannot_upload_for_someone_else_but_can_remove(): void
    {
        // Directors have no seat of their own in Permissions::ROLE_PERMISSIONS; they
        // reach the same ['management', 'hr'] gate as HR only through effectiveRole()
        // folding 'director' into 'management'. This is the only coverage of that path.
        $director = $this->person('director');
        $them = $this->person('employee');
        Storage::disk('public')->put("covers/{$them->id}/a.jpg", 'x');
        $them->update(['cover_path' => "covers/{$them->id}/a.jpg"]);

        $this->signIn($director)->post(route('employees.cover.update', $them), ['photo' => UploadedFile::fake()->image('c.jpg')])->assertForbidden();

        $this->signIn($director)->post(route('employees.cover.destroy', $them))->assertRedirect();
        $this->assertNull($them->fresh()->cover_path);
        Storage::disk('public')->assertMissing("covers/{$them->id}/a.jpg");
    }

    public function test_owner_removes_own_cover(): void
    {
        $me = $this->person('employee');
        Storage::disk('public')->put("covers/{$me->id}/a.jpg", 'x');
        $me->update(['cover_path' => "covers/{$me->id}/a.jpg"]);

        $this->signIn($me)->post(route('employees.cover.destroy', $me))->assertRedirect();

        $this->assertNull($me->fresh()->cover_path);
        Storage::disk('public')->assertMissing("covers/{$me->id}/a.jpg");
    }

    public function test_a_colleague_cannot_remove_someone_elses_cover(): void
    {
        $me = $this->person('employee');
        $them = $this->person('employee');
        $them->update(['cover_path' => "covers/{$them->id}/a.jpg"]);

        $this->signIn($me)->post(route('employees.cover.destroy', $them))->assertForbidden();
        $this->assertSame("covers/{$them->id}/a.jpg", $them->fresh()->cover_path);
    }

    public function test_another_tenants_employee_is_a_404_even_for_hr(): void
    {
        $hr = $this->person('hr');
        $otherTenant = Tenant::create(['slug' => 'beta', 'name' => 'Beta', 'initials' => 'BT']);
        $stranger = $this->person('employee', $otherTenant);

        $this->signIn($hr)->post(route('employees.cover.update', $stranger), ['photo' => UploadedFile::fake()->image('c.jpg')])->assertNotFound();
        $this->signIn($hr)->post(route('employees.cover.destroy', $stranger))->assertNotFound();
    }

    public function test_bad_uploads_are_rejected(): void
    {
        $me = $this->person('employee');

        $this->signIn($me)->post(route('employees.cover.update', $me), ['photo' => UploadedFile::fake()->create('big.jpg', 6000, 'image/jpeg')])->assertSessionHasErrors('photo');
        $this->signIn($me)->post(route('employees.cover.update', $me), ['photo' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')])->assertSessionHasErrors('photo');
        $this->signIn($me)->post(route('employees.cover.update', $me), [])->assertSessionHasErrors('photo');
    }

    public function test_cover_renders_for_the_owner_a_colleague_and_on_the_slim_card(): void
    {
        $me = $this->person('employee');
        $me->update(['cover_path' => "covers/{$me->id}/a.jpg"]);
        $url = Storage::disk('public')->url("covers/{$me->id}/a.jpg");

        $own = $this->signIn($me)->get(route('app.screen', ['screen' => 'profile', 'emp' => $me->id]))->assertOk()->getContent();
        $this->assertStringContainsString('class="uj-cover-hero"', $own);
        $this->assertStringContainsString('uj-has-cover', $own);
        $this->assertStringContainsString($url, $own);
        $this->assertStringContainsString('Change cover', $own);
        $this->assertStringContainsString(route('employees.cover.destroy', $me), $own);

        $hr = $this->person('hr');
        $full = $this->signIn($hr)->get(route('app.screen', ['screen' => 'profile', 'emp' => $me->id]))->getContent();
        $this->assertStringContainsString($url, $full);
        $this->assertStringNotContainsString('Change cover', $full);
        $this->assertStringContainsString('Remove cover', $full);

        $peer = $this->person('employee');
        $slim = $this->signIn($peer)->get(route('app.screen', ['screen' => 'profile', 'emp' => $me->id]))->getContent();
        $this->assertStringContainsString('class="uj-cover-hero"', $slim);
        $this->assertStringContainsString($url, $slim);
        $this->assertStringNotContainsString('Change cover', $slim);
        $this->assertStringNotContainsString('Remove cover', $slim);
    }

    public function test_a_cover_replaces_the_wallpaper_on_the_profile_screen(): void
    {
        $me = $this->person('employee');
        $me->user->appearance = ['wallpaper' => 'preset:dusk', 'wallpaper_path' => null, 'dim' => 'soft'];
        $me->user->save();

        $elsewhere = $this->signIn($me)->get(route('app.screen', ['screen' => 'attendance']))->getContent();
        $this->assertStringContainsString('uj-has-wallpaper', $elsewhere);

        $me->update(['cover_path' => 'preset:moss']);
        $own = $this->signIn($me)->get(route('app.screen', ['screen' => 'profile', 'emp' => $me->id]))->getContent();
        $this->assertStringContainsString('uj-has-cover', $own);
        $this->assertStringNotContainsString('uj-has-wallpaper', $own);
        $this->assertStringNotContainsString('class="uj-wallpaper"', $own);
    }

    public function test_no_cover_shows_the_invitation_to_the_owner_only(): void
    {
        $me = $this->person('employee');

        $own = $this->signIn($me)->get(route('app.screen', ['screen' => 'profile', 'emp' => $me->id]))->getContent();
        $this->assertStringContainsString('Pick a colour or upload a photo', $own);
        $this->assertStringNotContainsString('class="uj-cover-hero"', $own);
        $this->assertStringNotContainsString('uj-has-cover', $own);

        $hr = $this->person('hr');
        $other = $this->signIn($hr)->get(route('app.screen', ['screen' => 'profile', 'emp' => $me->id]))->getContent();
        $this->assertStringNotContainsString('Pick a colour or upload a photo', $other);
        $this->assertStringNotContainsString('Remove cover', $other);
    }

    public function test_owner_picks_a_preset_and_the_previous_photo_file_is_deleted(): void
    {
        $me = $this->person('employee');
        Storage::disk('public')->put("covers/{$me->id}/old.jpg", 'x');
        $me->update(['cover_path' => "covers/{$me->id}/old.jpg"]);

        $this->signIn($me)->post(route('employees.cover.update', $me), ['preset' => 'moss'])
            ->assertRedirect();

        $this->assertSame('preset:moss', $me->fresh()->cover_path);
        Storage::disk('public')->assertMissing("covers/{$me->id}/old.jpg");
    }

    public function test_an_unknown_preset_is_rejected(): void
    {
        $me = $this->person('employee');

        $this->signIn($me)->post(route('employees.cover.update', $me), ['preset' => 'neon'])
            ->assertSessionHasErrors('preset');
        $this->signIn($me)->post(route('employees.cover.update', $me), [])
            ->assertSessionHasErrors('photo');
    }

    public function test_a_colleague_cannot_pick_a_preset_for_someone_else(): void
    {
        $me = $this->person('employee');
        $them = $this->person('employee');

        $this->signIn($me)->post(route('employees.cover.update', $them), ['preset' => 'moss'])
            ->assertForbidden();
        $this->assertNull($them->fresh()->cover_path);
    }

    public function test_removing_a_preset_cover_touches_no_file(): void
    {
        $me = $this->person('employee');
        $me->update(['cover_path' => 'preset:sand']);

        $this->signIn($me)->post(route('employees.cover.destroy', $me))->assertRedirect();

        $this->assertNull($me->fresh()->cover_path);
    }

    public function test_a_preset_cover_renders_as_a_gradient_and_marks_the_chip(): void
    {
        $me = $this->person('employee');
        $me->update(['cover_path' => 'preset:moss']);

        $html = $this->signIn($me)->get(route('app.screen', ['screen' => 'profile', 'emp' => $me->id]))->getContent();

        $this->assertStringContainsString('class="uj-cover-hero"', $html);
        $this->assertStringContainsString(config('amanahku.wallpaper_presets.moss'), $html);
        $this->assertMatchesRegularExpression('/value="moss"[^>]*data-on="1"/', $html);
        $this->assertMatchesRegularExpression('/value="dawn"[^>]*data-on="0"/', $html);
    }

    public function test_a_failed_replacement_shows_the_error_on_the_profile(): void
    {
        $me = $this->person('employee');
        $me->update(['cover_path' => "covers/{$me->id}/a.jpg"]);
        $profile = route('app.screen', ['screen' => 'profile', 'emp' => $me->id]);

        $this->signIn($me)->from($profile)
            ->post(route('employees.cover.update', $me), ['photo' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')])
            ->assertRedirect($profile);

        $html = $this->get($profile)->getContent();
        $this->assertStringContainsString('uj-cover-picker-error', $html);
    }
}
