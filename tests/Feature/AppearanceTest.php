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
 * Per-user workspace wallpaper: the users.appearance column, the presets, the
 * AppearanceController endpoints and what layouts/app.blade.php renders from them.
 * Spec: docs/superpowers/specs/2026-09-03-custom-backgrounds-design.html
 */
class AppearanceTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Tenant $tenant;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create(['name' => 'Demo', 'email' => 'demo@example.com', 'password' => Hash::make('password')]);
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $this->user->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $this->employee = Employee::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $this->user->id,
            'name' => 'Demo', 'status' => 'active', 'workload' => 'green',
        ]);
    }

    private function actingInTenant(): self
    {
        $this->actingAs($this->user)->withSession(['current_tenant' => $this->tenant->id]);

        return $this;
    }

    public function test_appearance_column_round_trips_as_array(): void
    {
        $this->user->appearance = ['wallpaper' => 'preset:dawn', 'dim' => 'soft'];
        $this->user->save();

        $this->assertSame(['wallpaper' => 'preset:dawn', 'dim' => 'soft'], $this->user->fresh()->appearance);
        $this->assertNull(User::create(['name' => 'B', 'email' => 'b@example.com', 'password' => Hash::make('x')])->appearance);
    }

    public function test_cover_path_column_exists_on_employees(): void
    {
        $this->employee->update(['cover_path' => 'covers/1/abc.jpg']);

        $this->assertSame('covers/1/abc.jpg', $this->employee->fresh()->cover_path);
    }

    public function test_six_presets_and_three_dims_are_configured(): void
    {
        $this->assertSame(['dawn', 'dusk', 'paper', 'moss', 'slate', 'sand'], array_keys(config('amanahku.wallpaper_presets')));
        $this->assertSame(['none' => 0, 'soft' => 30, 'strong' => 55], config('amanahku.wallpaper_dims'));
    }

    public function test_picking_a_preset_saves_it(): void
    {
        $this->actingInTenant()->postJson(route('account.appearance'), ['wallpaper' => 'preset:dusk', 'dim' => 'strong'])
            ->assertNoContent();

        $this->assertSame(['wallpaper' => 'preset:dusk', 'wallpaper_path' => null, 'dim' => 'strong'], $this->user->fresh()->appearance);
    }

    public function test_unknown_preset_and_unknown_dim_are_rejected(): void
    {
        $this->actingInTenant()->postJson(route('account.appearance'), ['wallpaper' => 'preset:neon'])->assertStatus(422);
        $this->actingInTenant()->postJson(route('account.appearance'), ['wallpaper' => 'none', 'dim' => 'max'])->assertStatus(422);
    }

    public function test_dim_defaults_to_soft_and_none_clears_selection_but_keeps_upload(): void
    {
        $this->user->appearance = ['wallpaper' => 'upload', 'wallpaper_path' => 'wallpapers/1/a.jpg', 'dim' => 'strong'];
        $this->user->save();

        $this->actingInTenant()->postJson(route('account.appearance'), ['wallpaper' => 'none'])->assertNoContent();

        $this->assertSame(['wallpaper' => 'none', 'wallpaper_path' => 'wallpapers/1/a.jpg', 'dim' => 'strong'], $this->user->fresh()->appearance);

        $this->user->appearance = null;
        $this->user->save();
        $this->actingInTenant()->postJson(route('account.appearance'), ['wallpaper' => 'preset:dawn'])->assertNoContent();
        $this->assertSame('soft', $this->user->fresh()->appearance['dim']);
    }

    public function test_uploading_a_photo_stores_it_selects_it_and_replaces_the_old_one(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('wallpapers/'.$this->user->id.'/old.jpg', 'x');
        $this->user->appearance = ['wallpaper' => 'preset:dawn', 'wallpaper_path' => 'wallpapers/'.$this->user->id.'/old.jpg', 'dim' => 'soft'];
        $this->user->save();

        $this->actingInTenant()->post(route('account.appearance'), [
            'wallpaper' => 'upload',
            'photo' => UploadedFile::fake()->image('beach.jpg', 1200, 800),
        ])->assertRedirect();

        $a = $this->user->fresh()->appearance;
        $this->assertSame('upload', $a['wallpaper']);
        $this->assertStringStartsWith('wallpapers/'.$this->user->id.'/', $a['wallpaper_path']);
        Storage::disk('public')->assertExists($a['wallpaper_path']);
        Storage::disk('public')->assertMissing('wallpapers/'.$this->user->id.'/old.jpg');
    }

    public function test_upload_selected_without_a_photo_or_prior_upload_is_rejected(): void
    {
        $this->actingInTenant()->postJson(route('account.appearance'), ['wallpaper' => 'upload'])->assertStatus(422);
    }

    public function test_oversize_or_non_image_upload_is_rejected(): void
    {
        Storage::fake('public');

        $this->actingInTenant()->postJson(route('account.appearance'), [
            'wallpaper' => 'upload', 'photo' => UploadedFile::fake()->create('big.jpg', 6000, 'image/jpeg'),
        ])->assertStatus(422);

        $this->actingInTenant()->postJson(route('account.appearance'), [
            'wallpaper' => 'upload', 'photo' => UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'),
        ])->assertStatus(422);
    }

    public function test_deleting_the_photo_removes_the_file_and_falls_back_to_none(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('wallpapers/'.$this->user->id.'/a.jpg', 'x');
        $this->user->appearance = ['wallpaper' => 'upload', 'wallpaper_path' => 'wallpapers/'.$this->user->id.'/a.jpg', 'dim' => 'soft'];
        $this->user->save();

        $this->actingInTenant()->postJson(route('account.appearance.photo.destroy'))->assertNoContent();

        Storage::disk('public')->assertMissing('wallpapers/'.$this->user->id.'/a.jpg');
        $this->assertSame(['wallpaper' => 'none', 'wallpaper_path' => null, 'dim' => 'soft'], $this->user->fresh()->appearance);
    }

    public function test_deleting_the_photo_while_a_preset_is_selected_keeps_the_preset(): void
    {
        Storage::fake('public');
        $this->user->appearance = ['wallpaper' => 'preset:moss', 'wallpaper_path' => 'wallpapers/'.$this->user->id.'/a.jpg', 'dim' => 'soft'];
        $this->user->save();

        $this->actingInTenant()->postJson(route('account.appearance.photo.destroy'))->assertNoContent();

        $this->assertSame('preset:moss', $this->user->fresh()->appearance['wallpaper']);
        $this->assertNull($this->user->fresh()->appearance['wallpaper_path']);
    }

    public function test_guest_cannot_save_appearance(): void
    {
        $this->postJson(route('account.appearance'), ['wallpaper' => 'none'])->assertUnauthorized();
    }

    public function test_layout_renders_the_preset_wallpaper_for_its_owner_only(): void
    {
        $this->user->appearance = ['wallpaper' => 'preset:dusk', 'wallpaper_path' => null, 'dim' => 'strong'];
        $this->user->save();

        $html = $this->actingInTenant()->get(route('app.screen', 'security'))->assertOk()->getContent();

        $this->assertStringContainsString('uj-has-wallpaper', $html);
        $this->assertStringContainsString('class="uj-wallpaper" data-dim="strong"', $html);
        $this->assertStringContainsString(config('amanahku.wallpaper_presets.dusk'), $html);

        $other = User::create(['name' => 'Other', 'email' => 'other@example.com', 'password' => Hash::make('password')]);
        $other->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $html = $this->actingAs($other)->withSession(['current_tenant' => $this->tenant->id])->get(route('app.screen', 'security'))->assertOk()->getContent();
        $this->assertStringNotContainsString('uj-has-wallpaper', $html);
    }

    public function test_layout_renders_the_uploaded_wallpaper_url(): void
    {
        $this->user->appearance = ['wallpaper' => 'upload', 'wallpaper_path' => 'wallpapers/1/a.jpg', 'dim' => 'soft'];
        $this->user->save();

        $html = $this->actingInTenant()->get(route('app.screen', 'security'))->getContent();

        $this->assertStringContainsString('/storage/wallpapers/1/a.jpg', $html);
        $this->assertStringContainsString('data-dim="soft"', $html);
    }

    public function test_wallpaper_none_renders_nothing(): void
    {
        $this->user->appearance = ['wallpaper' => 'none', 'wallpaper_path' => 'wallpapers/1/a.jpg', 'dim' => 'soft'];
        $this->user->save();

        $html = $this->actingInTenant()->get(route('app.screen', 'security'))->getContent();

        $this->assertStringNotContainsString('uj-has-wallpaper', $html);
        $this->assertStringNotContainsString('uj-wallpaper"', $html);
    }
}
