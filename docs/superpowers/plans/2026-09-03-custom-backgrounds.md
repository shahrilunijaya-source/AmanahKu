# Custom Backgrounds Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A per-user workspace wallpaper (preset gradient or uploaded photo, with a dim level) behind every app screen, and a per-employee profile cover photo that blurs and fades into the profile page.

**Architecture:** Two small columns (`users.appearance` json, `employees.cover_path` string), two thin controllers (`AppearanceController`, `EmployeeCoverController`), files on the existing `public` disk exactly like the tenant logo. The layout reads the signed-in user's appearance and renders one fixed wallpaper div; three CSS rules scoped under `body.uj-has-wallpaper` do the rest. The profile screen renders the cover as two masked copies of the same image, no JS.

**Tech Stack:** Laravel 13, PHP 8.5, Blade + Alpine, Tailwind v4 (tokens in `resources/css/app.css`), PHPUnit 12, `App\Support\ImageCompressor`, `Storage::fake('public')` in tests.

**Spec:** `docs/superpowers/specs/2026-09-03-custom-backgrounds-design.html`

## Global Constraints

- Uploads: `image`, `mimes:jpeg,jpg,png,webp`, `max:5120` (5 MB). After storing, call `ImageCompressor::compress(Storage::disk('public')->path($path), (string) $file->getMimeType())`.
- Disk is always `public`. Paths: `wallpapers/{userId}/…` and `covers/{employeeId}/…` (Laravel hashed filenames via `store()`).
- Replacing or removing an image deletes the previous file.
- Every employee route checks `abort_unless($employee->tenant_id === app(CurrentTenant::class)->id(), 404)` before anything else. Route-model binding is NOT tenant-scoped in this app.
- Wallpaper renders only in `layouts/app.blade.php` when `! $embed` and the user has one. Never on login, wizard, or embedded panels.
- Presets: `dawn`, `dusk`, `paper`, `moss`, `slate`, `sand`. Dim: `none`, `soft`, `strong`. Default dim `soft`.
- `backdrop-filter` must be written as an inline `style=""`, never in `app.css` (Lightning CSS drops the unprefixed property; see the comment on `.uj-hd-fade` in app.css).
- All user-facing strings bilingual via the existing `x-text="$store.ui.lang==='en' ? '…' : '…'"` pattern.
- Run `vendor/bin/pint --dirty --format agent` after any PHP change. Run tests with `php artisan test --compact --filter=<Name>`.
- Commit after every task. Never `git stash`. Do not touch `public/build` until the final task.

---

### Task 1: Columns, casts, presets config

**Files:**
- Create: `database/migrations/2026_09_03_140000_add_appearance_to_users_and_cover_to_employees.php`
- Modify: `app/Models/User.php:47` (casts array)
- Modify: `config/amanahku.php` (add `wallpaper_presets` and `wallpaper_dims`)
- Test: `tests/Feature/AppearanceTest.php` (created here, extended in later tasks)

**Interfaces:**
- Produces: `User::$appearance` as `array{wallpaper?: string, wallpaper_path?: string, dim?: string}|null`; `Employee::$cover_path` as `?string`; `config('amanahku.wallpaper_presets')` as `array<string, string>` (key => CSS background value); `config('amanahku.wallpaper_dims')` as `array<string, int>` (key => canvas percentage).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=AppearanceTest`
Expected: FAIL (column `appearance` missing / config null).

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Personal workspace look: {"wallpaper": "preset:dawn" | "upload" | absent,
            // "wallpaper_path": "wallpapers/…", "dim": "none|soft|strong"}. Read by
            // layouts/app.blade.php on every page; only the owner ever sees it.
            $table->json('appearance')->nullable()->after('dashboard_prefs');
        });

        Schema::table('employees', function (Blueprint $table) {
            // Profile cover photo on the public disk. Everyone who opens the profile sees it.
            // `photo` beside it is an unused avatar column; left alone on purpose.
            $table->string('cover_path', 200)->nullable()->after('photo');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('appearance'));
        Schema::table('employees', fn (Blueprint $table) => $table->dropColumn('cover_path'));
    }
};
```

- [ ] **Step 4: Add the cast and the config**

In `app/Models/User.php` casts array, after `'dashboard_prefs' => 'array',` add:

```php
            'appearance' => 'array',
```

Also add to the class docblock, next to the `$dashboard_prefs` property line:

```php
 * @property array{wallpaper?: string, wallpaper_path?: string, dim?: string}|null $appearance
```

In `config/amanahku.php`, after `'avatar_palette' => [...]`, add:

```php
    // Workspace wallpaper presets (Account & security → Appearance). Key is what
    // users.appearance stores as "preset:<key>"; value is the CSS background. Gradients,
    // not photos: nothing to license, nothing to ship, instant to paint.
    'wallpaper_presets' => [
        'dawn' => 'linear-gradient(160deg, #f7d9c4 0%, #e9b7a8 45%, #8fa9c9 100%)',
        'dusk' => 'linear-gradient(160deg, #2f3f5c 0%, #6b5b7b 55%, #d78f6a 100%)',
        'paper' => 'radial-gradient(70% 70% at 30% 20%, #ffffff 0%, #ece9e1 60%, #ddd9cf 100%)',
        'moss' => 'linear-gradient(160deg, #dfe8d6 0%, #9fb59a 55%, #4d6b55 100%)',
        'slate' => 'linear-gradient(160deg, #3a3f4a 0%, #5b6470 55%, #a9b1bb 100%)',
        'sand' => 'linear-gradient(160deg, #f3e6cf 0%, #d9bf98 55%, #8d6f4c 100%)',
    ],
    // How much page canvas is laid over the wallpaper, as a percentage.
    'wallpaper_dims' => ['none' => 0, 'soft' => 30, 'strong' => 55],
```

- [ ] **Step 5: Run migration on the dev DB and the tests**

Run: `php artisan migrate --no-interaction` then `php artisan test --compact --filter=AppearanceTest`
Expected: PASS (3 tests).

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations app/Models/User.php config/amanahku.php tests/Feature/AppearanceTest.php
git commit -m "feat(appearance): users.appearance and employees.cover_path columns, wallpaper presets"
```

---

### Task 2: Appearance endpoints

**Files:**
- Create: `app/Http/Requests/UpdateAppearanceRequest.php`
- Create: `app/Http/Controllers/AppearanceController.php`
- Modify: `routes/web.php:345` (after the `security.ai-key.revoke` route)
- Test: `tests/Feature/AppearanceTest.php`

**Interfaces:**
- Consumes: `User::$appearance` (Task 1), `config('amanahku.wallpaper_presets')`.
- Produces: routes `account.appearance` (POST `/app/account/appearance`) and `account.appearance.photo.destroy` (POST `/app/account/appearance/photo/delete`). Both return `204` for `expectsJson()` requests, else `back()`.
- Stored shape: `['wallpaper' => 'none'|'preset:<key>'|'upload', 'wallpaper_path' => ?string, 'dim' => 'none'|'soft'|'strong']`.

- [ ] **Step 1: Write the failing tests** (append inside `AppearanceTest`)

```php
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
        $this->user->update(['appearance' => ['wallpaper' => 'upload', 'wallpaper_path' => 'wallpapers/1/a.jpg', 'dim' => 'strong']]);

        $this->actingInTenant()->postJson(route('account.appearance'), ['wallpaper' => 'none'])->assertNoContent();

        $this->assertSame(['wallpaper' => 'none', 'wallpaper_path' => 'wallpapers/1/a.jpg', 'dim' => 'strong'], $this->user->fresh()->appearance);

        $this->user->update(['appearance' => null]);
        $this->actingInTenant()->postJson(route('account.appearance'), ['wallpaper' => 'preset:dawn'])->assertNoContent();
        $this->assertSame('soft', $this->user->fresh()->appearance['dim']);
    }

    public function test_uploading_a_photo_stores_it_selects_it_and_replaces_the_old_one(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        \Illuminate\Support\Facades\Storage::disk('public')->put('wallpapers/'.$this->user->id.'/old.jpg', 'x');
        $this->user->update(['appearance' => ['wallpaper' => 'preset:dawn', 'wallpaper_path' => 'wallpapers/'.$this->user->id.'/old.jpg', 'dim' => 'soft']]);

        $this->actingInTenant()->post(route('account.appearance'), [
            'wallpaper' => 'upload',
            'photo' => \Illuminate\Http\UploadedFile::fake()->image('beach.jpg', 1200, 800),
        ])->assertRedirect();

        $a = $this->user->fresh()->appearance;
        $this->assertSame('upload', $a['wallpaper']);
        $this->assertStringStartsWith('wallpapers/'.$this->user->id.'/', $a['wallpaper_path']);
        \Illuminate\Support\Facades\Storage::disk('public')->assertExists($a['wallpaper_path']);
        \Illuminate\Support\Facades\Storage::disk('public')->assertMissing('wallpapers/'.$this->user->id.'/old.jpg');
    }

    public function test_upload_selected_without_a_photo_or_prior_upload_is_rejected(): void
    {
        $this->actingInTenant()->postJson(route('account.appearance'), ['wallpaper' => 'upload'])->assertStatus(422);
    }

    public function test_oversize_or_non_image_upload_is_rejected(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');

        $this->actingInTenant()->postJson(route('account.appearance'), [
            'wallpaper' => 'upload', 'photo' => \Illuminate\Http\UploadedFile::fake()->create('big.jpg', 6000, 'image/jpeg'),
        ])->assertStatus(422);

        $this->actingInTenant()->postJson(route('account.appearance'), [
            'wallpaper' => 'upload', 'photo' => \Illuminate\Http\UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf'),
        ])->assertStatus(422);
    }

    public function test_deleting_the_photo_removes_the_file_and_falls_back_to_none(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        \Illuminate\Support\Facades\Storage::disk('public')->put('wallpapers/'.$this->user->id.'/a.jpg', 'x');
        $this->user->update(['appearance' => ['wallpaper' => 'upload', 'wallpaper_path' => 'wallpapers/'.$this->user->id.'/a.jpg', 'dim' => 'soft']]);

        $this->actingInTenant()->postJson(route('account.appearance.photo.destroy'))->assertNoContent();

        \Illuminate\Support\Facades\Storage::disk('public')->assertMissing('wallpapers/'.$this->user->id.'/a.jpg');
        $this->assertSame(['wallpaper' => 'none', 'wallpaper_path' => null, 'dim' => 'soft'], $this->user->fresh()->appearance);
    }

    public function test_deleting_the_photo_while_a_preset_is_selected_keeps_the_preset(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $this->user->update(['appearance' => ['wallpaper' => 'preset:moss', 'wallpaper_path' => 'wallpapers/'.$this->user->id.'/a.jpg', 'dim' => 'soft']]);

        $this->actingInTenant()->postJson(route('account.appearance.photo.destroy'))->assertNoContent();

        $this->assertSame('preset:moss', $this->user->fresh()->appearance['wallpaper']);
        $this->assertNull($this->user->fresh()->appearance['wallpaper_path']);
    }

    public function test_guest_cannot_save_appearance(): void
    {
        $this->postJson(route('account.appearance'), ['wallpaper' => 'none'])->assertUnauthorized();
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=AppearanceTest`
Expected: FAIL with route `account.appearance` not defined.

- [ ] **Step 3: Write the form request**

```php
<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a workspace wallpaper save from the Appearance card.
 *
 * `wallpaper` is one of none | preset:<key> | upload. `upload` needs either a file in
 * this request or a photo already stored on the account, otherwise there is nothing
 * to show and the save is refused rather than silently stored as blank.
 */
class UpdateAppearanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $presets = array_map(fn (string $k) => 'preset:'.$k, array_keys(config('amanahku.wallpaper_presets')));

        return [
            'wallpaper' => ['required', 'string', Rule::in(['none', 'upload', ...$presets])],
            'dim' => ['nullable', 'string', Rule::in(array_keys(config('amanahku.wallpaper_dims')))],
            'photo' => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
        ];
    }

    public function withValidator(\Illuminate\Validation\Validator $validator): void
    {
        $validator->after(function (\Illuminate\Validation\Validator $v): void {
            $hasStored = ! empty($this->user()?->appearance['wallpaper_path'] ?? null);
            if ($this->input('wallpaper') === 'upload' && ! $this->hasFile('photo') && ! $hasStored) {
                $v->errors()->add('photo', 'Choose a photo to upload first.');
            }
        });
    }
}
```

- [ ] **Step 4: Write the controller**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\UpdateAppearanceRequest;
use App\Support\ImageCompressor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

/**
 * The signed-in user's workspace wallpaper (Account & security → Appearance).
 *
 * Stored on users.appearance; only the owner ever sees it. One personal photo per
 * account: a new upload replaces and deletes the previous file. Choosing a preset
 * keeps the photo on disk so the user can switch back without re-uploading.
 */
class AppearanceController extends Controller
{
    private const DISK = 'public';

    public function update(UpdateAppearanceRequest $request): Response|RedirectResponse
    {
        $user = $request->user();
        $current = $user->appearance ?? [];
        $path = $current['wallpaper_path'] ?? null;

        if ($request->hasFile('photo')) {
            $file = $request->file('photo');
            $new = $file->store('wallpapers/'.$user->id, self::DISK);
            abort_unless($new !== false, 500, 'Photo could not be stored.');
            ImageCompressor::compress(Storage::disk(self::DISK)->path($new), (string) $file->getMimeType());

            if ($path && $path !== $new) {
                Storage::disk(self::DISK)->delete($path);
            }
            $path = $new;
        }

        $user->appearance = [
            'wallpaper' => $request->input('wallpaper'),
            'wallpaper_path' => $path,
            'dim' => $request->input('dim') ?: ($current['dim'] ?? 'soft'),
        ];
        $user->save();

        return $request->expectsJson() ? response()->noContent() : back()->with('ok', 'Background saved.');
    }

    public function destroyPhoto(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        $current = $user->appearance ?? [];

        if (! empty($current['wallpaper_path'])) {
            Storage::disk(self::DISK)->delete($current['wallpaper_path']);
        }

        $user->appearance = [
            'wallpaper' => ($current['wallpaper'] ?? 'none') === 'upload' ? 'none' : ($current['wallpaper'] ?? 'none'),
            'wallpaper_path' => null,
            'dim' => $current['dim'] ?? 'soft',
        ];
        $user->save();

        return $request->expectsJson() ? response()->noContent() : back()->with('ok', 'Photo removed.');
    }
}
```

- [ ] **Step 5: Register the routes**

In `routes/web.php`, directly after the line `Route::post('/app/security/ai-key/revoke', …)->name('security.ai-key.revoke');`, add:

```php
        // Personal workspace wallpaper (Account & security → Appearance). Own row only.
        Route::post('/app/account/appearance', [AppearanceController::class, 'update'])->name('account.appearance');
        Route::post('/app/account/appearance/photo/delete', [AppearanceController::class, 'destroyPhoto'])->name('account.appearance.photo.destroy');
```

Add `use App\Http\Controllers\AppearanceController;` to the imports at the top of `routes/web.php` (alphabetical, near `AppController`).

- [ ] **Step 6: Run tests**

Run: `php artisan test --compact --filter=AppearanceTest`
Expected: PASS (12 tests).

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Requests/UpdateAppearanceRequest.php app/Http/Controllers/AppearanceController.php routes/web.php tests/Feature/AppearanceTest.php
git commit -m "feat(appearance): save a preset or uploaded wallpaper and its dim level"
```

---

### Task 3: Wallpaper in the layout

**Files:**
- Modify: `resources/views/layouts/app.blade.php` (the shell `<div x-data=…>` at ~line 27 and the `.uj-hd-fade` line at ~line 62)
- Modify: `resources/css/app.css` (append after the `.uj-hd-fade` block, ~line 866)
- Test: `tests/Feature/AppearanceTest.php`

**Interfaces:**
- Consumes: `auth()->user()->appearance`, `config('amanahku.wallpaper_presets')`, `config('amanahku.wallpaper_dims')`.
- Produces: class `uj-has-wallpaper` on the shell div; a `<div class="uj-wallpaper" data-dim="soft" style="background-image:…">` as its first child; CSS classes `.uj-wallpaper`, `.uj-plate`.

- [ ] **Step 1: Write the failing tests** (append inside `AppearanceTest`)

```php
    public function test_layout_renders_the_preset_wallpaper_for_its_owner_only(): void
    {
        $this->user->update(['appearance' => ['wallpaper' => 'preset:dusk', 'wallpaper_path' => null, 'dim' => 'strong']]);

        $html = $this->actingInTenant()->get(route('app.screen', 'security'))->assertOk()->getContent();

        $this->assertStringContainsString('uj-has-wallpaper', $html);
        $this->assertStringContainsString('class="uj-wallpaper" data-dim="strong"', $html);
        $this->assertStringContainsString(config('amanahku.wallpaper_presets.dusk'), $html);

        $other = User::create(['name' => 'Other', 'email' => 'other@example.com', 'password' => Hash::make('password')]);
        $other->tenants()->attach($this->tenant->id, ['role' => 'employee']);
        $html = $this->actingAs($other)->withSession(['current_tenant' => $this->tenant->id])->get(route('app.screen', 'security'))->getContent();
        $this->assertStringNotContainsString('uj-has-wallpaper', $html);
    }

    public function test_layout_renders_the_uploaded_wallpaper_url(): void
    {
        $this->user->update(['appearance' => ['wallpaper' => 'upload', 'wallpaper_path' => 'wallpapers/1/a.jpg', 'dim' => 'soft']]);

        $html = $this->actingInTenant()->get(route('app.screen', 'security'))->getContent();

        $this->assertStringContainsString('/storage/wallpapers/1/a.jpg', $html);
        $this->assertStringContainsString('data-dim="soft"', $html);
    }

    public function test_wallpaper_none_renders_nothing(): void
    {
        $this->user->update(['appearance' => ['wallpaper' => 'none', 'wallpaper_path' => 'wallpapers/1/a.jpg', 'dim' => 'soft']]);

        $html = $this->actingInTenant()->get(route('app.screen', 'security'))->getContent();

        $this->assertStringNotContainsString('uj-has-wallpaper', $html);
        $this->assertStringNotContainsString('uj-wallpaper"', $html);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=AppearanceTest`
Expected: FAIL on `uj-has-wallpaper` not found.

- [ ] **Step 3: Compute the wallpaper in the layout**

In `resources/views/layouts/app.blade.php`, inside the existing `@php` block after `$hasPins = …;`, add:

```php
    // Personal wallpaper (users.appearance). Only the owner sees it, never in an
    // embedded panel. Presets are gradients from config; an upload is a public-disk URL.
    $wp = null;
    if (! $embed && ($appearance = auth()->user()?->appearance)) {
        $choice = $appearance['wallpaper'] ?? 'none';
        if (str_starts_with($choice, 'preset:') && ($css = config('amanahku.wallpaper_presets.'.substr($choice, 7)))) {
            $wp = $css;
        } elseif ($choice === 'upload' && ! empty($appearance['wallpaper_path'])) {
            $wp = 'url('.e(\Illuminate\Support\Facades\Storage::disk('public')->url($appearance['wallpaper_path'])).')';
        }
    }
    $wpDim = $appearance['dim'] ?? 'soft';
```

On the shell `<div x-data="{ ai: false, …` element, change the `:class` binding line to include the static class:

```blade
     :class="{ 'uj-sb-collapsed': sbCollapsed, 'uj-sb-tree': sbStyle === 'tree' }"
     class="{{ $wp ? 'uj-has-wallpaper' : '' }}"
```

Immediately after that shell `<div … >` opening tag (before `@unless ($embed) @include('partials.sidebar')`), add:

```blade
    @if ($wp)
        <div class="uj-wallpaper" data-dim="{{ $wpDim }}" style="background-image:{{ $wp }};"></div>
    @endif
```

Change the existing `.uj-hd-fade` line so the header also gets the inline blur when a wallpaper is on. Replace:

```blade
        @include('partials.header')
```

with:

```blade
        @include('partials.header', ['headerStyle' => $wp ? 'backdrop-filter:blur(14px);-webkit-backdrop-filter:blur(14px);' : ''])
```

And in `resources/views/partials/header.blade.php` line 1, change `<header class="uj-header">` to:

```blade
<header class="uj-header" style="{{ $headerStyle ?? '' }}">
```

Wrap the page title block so it gets the plate. In the layout, change:

```blade
                                <div x-data="{ t: { en: @js($pageTitle), ms: @js($pageTitleMs) }, s: { en: @js($pageSub), ms: @js($pageSubMs) } }">
```

to:

```blade
                                <div class="{{ $wp ? 'uj-plate' : '' }}" style="{{ $wp ? 'backdrop-filter:blur(14px);-webkit-backdrop-filter:blur(14px);' : '' }}" x-data="{ t: { en: @js($pageTitle), ms: @js($pageTitleMs) }, s: { en: @js($pageSub), ms: @js($pageSubMs) } }">
```

- [ ] **Step 4: Add the CSS**

Append to `resources/css/app.css` right after the `.uj-hd-fade { … }` block:

```css
/* ── Personal wallpaper (users.appearance) ──
   One fixed layer under everything, a dim over it, and three things that change
   when it is on: the header goes translucent, the page title gets a paper plate,
   nothing else moves. Cards stay opaque: the ledger is still the ledger. The blur
   is inline on the elements (see .uj-hd-fade for why not here). */
.uj-wallpaper { position: fixed; inset: 0; z-index: 0; background-size: cover; background-position: center; pointer-events: none; }
.uj-wallpaper::after { content: ""; position: absolute; inset: 0; background: color-mix(in srgb, var(--canvas) 30%, transparent); }
.uj-wallpaper[data-dim="none"]::after { background: transparent; }
.uj-wallpaper[data-dim="strong"]::after { background: color-mix(in srgb, var(--canvas) 55%, transparent); }
.uj-has-wallpaper .uj-shell-main { background: transparent; }
.uj-has-wallpaper .uj-header { background: color-mix(in srgb, var(--canvas) 72%, transparent); }
.uj-has-wallpaper .uj-hd-fade { background: linear-gradient(to bottom, color-mix(in srgb, var(--canvas) 60%, transparent) 0%, transparent 100%); }
.uj-plate { display: inline-block; padding: 8px 12px 9px; margin-left: -12px; border-radius: 10px; background: color-mix(in srgb, var(--canvas) 74%, transparent); }
/* Small screens show more bare canvas than card, so the dim steps up one level. */
@media (max-width: 520px) {
    .uj-wallpaper[data-dim="none"]::after { background: color-mix(in srgb, var(--canvas) 30%, transparent); }
    .uj-wallpaper[data-dim="soft"]::after { background: color-mix(in srgb, var(--canvas) 55%, transparent); }
}
```

The shell div sets `background:var(--canvas)` inline; that is fine, the wallpaper is `position:fixed` above it and below the sidebar (`z-index:30`) and header (`z-index:20`). Verify `<main>` content still paints above the wallpaper: `.uj-shell-main` has `position:relative` inline without a z-index, so add `.uj-has-wallpaper .uj-shell-main { position: relative; z-index: 1; }` to the same CSS block (merge it into the `.uj-shell-main` rule above).

- [ ] **Step 5: Run tests and eyeball the dev site**

Run: `php artisan test --compact --filter=AppearanceTest` → PASS (15 tests).
Then set a preset for a dev account via tinker and open any screen in the worktree vhost:

```bash
php artisan tinker --execute 'App\Models\User::where("email","shazwanshah.unijaya@gmail.com")->first()->update(["appearance"=>["wallpaper"=>"preset:dawn","wallpaper_path"=>null,"dim"=>"soft"]]);'
bun run dev
```

Check: sidebar dark and untouched, header frosted, title on a plate, cards white, no horizontal scroll, dock opaque on a phone width.

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/layouts/app.blade.php resources/views/partials/header.blade.php resources/css/app.css tests/Feature/AppearanceTest.php
git commit -m "feat(appearance): render the personal wallpaper behind every app screen"
```

---

### Task 4: Appearance card and account-menu row

**Files:**
- Modify: `resources/views/screens/security.blade.php` (insert a card before the `AI access key` card, ~line 124)
- Modify: `resources/views/partials/header.blade.php:237-244` (account menu, after "My profile")
- Modify: `resources/css/app.css` (append tile styles after the wallpaper block from Task 3)
- Test: `tests/Feature/AppearanceTest.php`

**Interfaces:**
- Consumes: routes `account.appearance`, `account.appearance.photo.destroy` (Task 2); `.uj-wallpaper` markup (Task 3).
- Produces: nothing new for later tasks.

- [ ] **Step 1: Write the failing tests** (append inside `AppearanceTest`)

```php
    public function test_security_screen_shows_the_appearance_card_with_every_preset(): void
    {
        $html = $this->actingInTenant()->get(route('app.screen', 'security'))->assertOk()->getContent();

        $this->assertStringContainsString('Appearance', $html);
        foreach (['dawn', 'dusk', 'paper', 'moss', 'slate', 'sand'] as $key) {
            $this->assertStringContainsString('data-wallpaper="preset:'.$key.'"', $html);
        }
        $this->assertStringContainsString('data-wallpaper="none"', $html);
        $this->assertStringNotContainsString('data-wallpaper="upload"', $html, 'no photo tile before an upload');
    }

    public function test_security_screen_shows_the_photo_tile_after_an_upload(): void
    {
        $this->user->update(['appearance' => ['wallpaper' => 'upload', 'wallpaper_path' => 'wallpapers/1/a.jpg', 'dim' => 'soft']]);

        $html = $this->actingInTenant()->get(route('app.screen', 'security'))->getContent();

        $this->assertStringContainsString('data-wallpaper="upload"', $html);
        $this->assertStringContainsString('Remove photo', $html);
    }

    public function test_account_menu_links_to_the_background_card(): void
    {
        $html = $this->actingInTenant()->get(route('app.screen', 'dash'))->getContent();

        $this->assertStringContainsString(route('app.screen', 'security').'#appearance', $html);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=AppearanceTest`
Expected: FAIL on `data-wallpaper="preset:dawn"` not found.

- [ ] **Step 3: Add the card to the security screen**

Insert before `<div class="uj-card" style="padding:24px;">` that holds `AI access key` (the card at ~line 124):

```blade
    @php
        $ap = auth()->user()->appearance ?? [];
        $apChoice = $ap['wallpaper'] ?? 'none';
        $apPath = $ap['wallpaper_path'] ?? null;
        $apUrl = $apPath ? \Illuminate\Support\Facades\Storage::disk('public')->url($apPath) : null;
    @endphp
    {{-- Personal workspace wallpaper. Picking a tile saves at once and swaps the
         wallpaper behind this page in place; there is no Save button to scroll to. --}}
    <div class="uj-card" id="appearance" style="padding:24px;"
         x-data="appearanceCard({
            url: @js(route('account.appearance')),
            deleteUrl: @js(route('account.appearance.photo.destroy')),
            choice: @js($apChoice),
            dim: @js($ap['dim'] ?? 'soft'),
            photoUrl: @js($apUrl),
            presets: @js(config('amanahku.wallpaper_presets')),
         })">
        <h3 class="uj-card-title" style="margin-bottom:4px;" x-text="$store.ui.lang==='en' ? 'Appearance' : 'Penampilan'">Appearance</h3>
        <p style="font-size:13px;color:var(--muted);margin:0 0 16px;line-height:1.5;" x-text="$store.ui.lang==='en' ? 'A background for your workspace. Only you see it.' : 'Latar belakang untuk ruang kerja anda. Hanya anda yang melihatnya.'">A background for your workspace. Only you see it.</p>

        <div class="uj-wp-grid">
            <button type="button" class="uj-wp-tile uj-wp-tile--none" data-wallpaper="none" :data-on="choice === 'none'" @click="pick('none')">
                <span class="uj-wp-name" x-text="$store.ui.lang==='en' ? 'None' : 'Tiada'">None</span>
            </button>
            <template x-if="photoUrl">
                <button type="button" class="uj-wp-tile" data-wallpaper="upload" :data-on="choice === 'upload'" :style="'background-image:url(' + photoUrl + ')'" @click="pick('upload')">
                    <span class="uj-wp-name" x-text="$store.ui.lang==='en' ? 'Your photo' : 'Foto anda'">Your photo</span>
                </button>
            </template>
            @foreach (config('amanahku.wallpaper_presets') as $key => $css)
                <button type="button" class="uj-wp-tile" data-wallpaper="preset:{{ $key }}" :data-on="choice === 'preset:{{ $key }}'" style="background:{{ $css }};" @click="pick('preset:{{ $key }}')">
                    <span class="uj-wp-name">{{ ucfirst($key) }}</span>
                </button>
            @endforeach
            <label class="uj-wp-tile uj-wp-tile--upload">
                <input type="file" accept="image/jpeg,image/png,image/webp" style="display:none;" @change="upload($event)">
                <b>+</b>
                <span x-text="busy ? ($store.ui.lang==='en' ? 'Uploading…' : 'Memuat naik…') : ($store.ui.lang==='en' ? 'Upload photo' : 'Muat naik foto')">Upload photo</span>
            </label>
        </div>

        <div style="display:flex;align-items:center;gap:14px;margin-top:18px;padding-top:16px;border-top:1px solid var(--hairline-soft);font-size:12.5px;flex-wrap:wrap;">
            <span style="color:var(--muted);" x-text="$store.ui.lang==='en' ? 'Dim' : 'Malap'">Dim</span>
            <div class="uj-seg">
                <button type="button" :data-on="dim === 'none'" @click="setDim('none')" x-text="$store.ui.lang==='en' ? 'None' : 'Tiada'">None</button>
                <button type="button" :data-on="dim === 'soft'" @click="setDim('soft')" x-text="$store.ui.lang==='en' ? 'Soft' : 'Lembut'">Soft</button>
                <button type="button" :data-on="dim === 'strong'" @click="setDim('strong')" x-text="$store.ui.lang==='en' ? 'Strong' : 'Kuat'">Strong</button>
            </div>
            <span style="margin-left:auto;font-size:11.5px;color:var(--muted-soft);" x-show="!photoUrl">JPEG, PNG, WebP · 5 MB</span>
            <button type="button" x-show="photoUrl" x-cloak class="uj-btn-ghost" style="margin-left:auto;height:32px;font-size:12px;padding:0 12px;border:0;color:var(--muted);" @click="removePhoto()">
                <span x-text="$store.ui.lang==='en' ? 'Remove photo' : 'Buang foto'">Remove photo</span>
            </button>
            <p x-show="error" x-cloak x-text="error" style="flex-basis:100%;margin:0;color:var(--error);font-size:12px;"></p>
        </div>
    </div>
```

Then, at the bottom of `security.blade.php` (inside the existing `@push('scripts')` if the file has one; otherwise append a `<script>` at the end of the `@section('screen')`), add the Alpine component:

```blade
<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('appearanceCard', (cfg) => ({
        choice: cfg.choice, dim: cfg.dim, photoUrl: cfg.photoUrl, busy: false, error: '',
        csrf: document.querySelector('meta[name=csrf-token]').content,

        /* The wallpaper behind this page is swapped in place, so the pick is seen at
           once. A short blur over the crossfade keeps two pictures from reading as
           two objects mid-swap. */
        paint() {
            const shell = document.querySelector('[x-data*="sbCollapsed"]');
            let layer = document.querySelector('.uj-wallpaper');
            let bg = null;
            if (this.choice.startsWith('preset:')) bg = cfg.presets[this.choice.slice(7)];
            else if (this.choice === 'upload' && this.photoUrl) bg = 'url(' + this.photoUrl + ')';
            if (!bg) { layer?.remove(); shell?.classList.remove('uj-has-wallpaper'); document.querySelector('.uj-plate')?.classList.remove('uj-plate'); return; }
            if (!layer) { layer = document.createElement('div'); layer.className = 'uj-wallpaper'; shell.prepend(layer); }
            shell.classList.add('uj-has-wallpaper');
            layer.dataset.dim = this.dim;
            layer.style.transition = 'opacity 220ms cubic-bezier(.23,1,.32,1), filter 220ms cubic-bezier(.23,1,.32,1)';
            layer.style.filter = 'blur(6px)'; layer.style.opacity = '0.6';
            requestAnimationFrame(() => { layer.style.backgroundImage = bg; layer.style.filter = ''; layer.style.opacity = ''; });
        },
        async send(body) {
            this.error = '';
            const res = await fetch(cfg.url, { method: 'POST', headers: { 'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json' }, body });
            if (res.status === 422) { const j = await res.json(); this.error = Object.values(j.errors ?? {})[0]?.[0] ?? 'Could not save.'; return false; }
            if (!res.ok) { this.error = 'Could not save.'; return false; }
            return true;
        },
        form(extra = {}) {
            const f = new FormData(); f.append('wallpaper', this.choice); f.append('dim', this.dim);
            Object.entries(extra).forEach(([k, v]) => f.append(k, v));
            return f;
        },
        async pick(c) { const prev = this.choice; this.choice = c; this.paint(); if (!await this.send(this.form())) { this.choice = prev; this.paint(); } },
        async setDim(d) { const prev = this.dim; this.dim = d; this.paint(); if (!await this.send(this.form())) { this.dim = prev; this.paint(); } },
        async upload(e) {
            const file = e.target.files[0]; if (!file) return;
            this.busy = true;
            const prev = this.choice; this.choice = 'upload';
            const ok = await this.send(this.form({ photo: file }));
            this.busy = false; e.target.value = '';
            if (!ok) { this.choice = prev; return; }
            this.photoUrl = URL.createObjectURL(file); this.paint();
        },
        async removePhoto() {
            const res = await fetch(cfg.deleteUrl, { method: 'POST', headers: { 'X-CSRF-TOKEN': this.csrf, 'Accept': 'application/json' } });
            if (!res.ok) { this.error = 'Could not remove the photo.'; return; }
            this.photoUrl = null; if (this.choice === 'upload') this.choice = 'none'; this.paint();
        },
    }));
});
</script>
```

Note: `document.querySelector('[x-data*="sbCollapsed"]')` finds the shell div from `layouts/app.blade.php`. If a cleaner hook is preferred, add `id="uj-shell"` to that div in the layout and query that instead; either is acceptable, but be consistent.

- [ ] **Step 4: Add the tile CSS**

Append to `resources/css/app.css` after the wallpaper block:

```css
/* Appearance card tiles (Account & security). A press scales, the chosen one is
   ringed in the action red because choosing IS the action here. */
.uj-wp-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; }
@media (max-width: 640px) { .uj-wp-grid { grid-template-columns: repeat(3, 1fr); } }
.uj-wp-tile { position: relative; aspect-ratio: 4 / 3; border: 1px solid var(--hairline); border-radius: 9px; overflow: hidden; cursor: pointer; padding: 0; background-size: cover; background-position: center; text-align: left; transition: transform 160ms cubic-bezier(.23, 1, .32, 1); }
.uj-wp-tile:active { transform: scale(.97); }
.uj-wp-tile:focus-visible { outline: 2px solid var(--red); outline-offset: 2px; }
.uj-wp-tile[data-on] { outline: 2px solid var(--red); outline-offset: 2px; }
.uj-wp-tile[data-on]::after { content: "✓"; position: absolute; right: 6px; top: 6px; width: 18px; height: 18px; border-radius: 50%; background: var(--red); color: #fff; font-size: 11px; display: flex; align-items: center; justify-content: center; }
.uj-wp-name { position: absolute; left: 8px; bottom: 6px; font-size: 10.5px; color: #fff; text-shadow: 0 1px 2px rgba(0, 0, 0, .35); }
.uj-wp-tile--none { background: var(--canvas); }
.uj-wp-tile--none .uj-wp-name { color: var(--ink); text-shadow: none; }
.uj-wp-tile--upload { background: #fff; border-style: dashed; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 4px; color: var(--muted); font-size: 11px; }
.uj-wp-tile--upload b { font-size: 18px; font-weight: 400; color: var(--ink); line-height: 1; }
@media (prefers-reduced-motion: reduce) { .uj-wp-tile, .uj-wallpaper { transition: none !important; } }
```

- [ ] **Step 5: Add the account-menu row**

In `resources/views/partials/header.blade.php`, after the "My profile" `</a>` (before the "Account & security" link), add:

```blade
                <a href="{{ route('app.screen', 'security') }}#appearance" class="uj-acct-item" style="display:flex;align-items:center;gap:11px;padding:9px 10px;border-radius:8px;font-size:var(--t-base);color:var(--body);text-decoration:none;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><path d="M21 15l-5-5L5 21"></path></svg>
                    <span x-text="$store.ui.lang==='en' ? 'Background' : 'Latar belakang'">Background</span>
                </a>
```

- [ ] **Step 6: Run tests and click through**

Run: `php artisan test --compact --filter=AppearanceTest` → PASS (18 tests).
With `bun run dev` running, open Account & security: pick each tile (wallpaper swaps behind the page, no reload), change Dim, upload a JPEG, remove it, confirm the 422 message shows for a PDF.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/screens/security.blade.php resources/views/partials/header.blade.php resources/css/app.css tests/Feature/AppearanceTest.php
git commit -m "feat(appearance): Appearance card on Account & security, picks save in place"
```

---

### Task 5: Cover endpoints

**Files:**
- Create: `app/Http/Controllers/EmployeeCoverController.php`
- Modify: `routes/web.php:291` (after `employees.restore`)
- Test: `tests/Feature/ProfileCoverTest.php`

**Interfaces:**
- Consumes: `Employee::$cover_path` (Task 1), `Controller::hasTenantRole()` (existing, see `BuildsPeopleData.php:157`), `App\Tenancy\CurrentTenant`.
- Produces: routes `employees.cover.update` (POST `/app/employees/{employee}/cover`, field `photo`) and `employees.cover.destroy` (POST `/app/employees/{employee}/cover/delete`). Both redirect back.
- Authorisation: update = owner only (`$employee->user_id === $request->user()->id`); destroy = owner OR `hasTenantRole($request, ['management', 'hr'])` (the same rule as the profile's `$canEdit`).

- [ ] **Step 1: Write the failing tests**

```php
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

    private function as(Employee $e): self
    {
        $this->actingAs($e->user)->withSession(['current_tenant' => $e->tenant_id]);

        return $this;
    }

    public function test_owner_uploads_a_cover_and_it_replaces_the_previous_file(): void
    {
        $me = $this->person('employee');
        Storage::disk('public')->put("covers/{$me->id}/old.jpg", 'x');
        $me->update(['cover_path' => "covers/{$me->id}/old.jpg"]);

        $this->as($me)->post(route('employees.cover.update', $me), ['photo' => UploadedFile::fake()->image('c.jpg', 1600, 600)])
            ->assertRedirect();

        $path = $me->fresh()->cover_path;
        $this->assertStringStartsWith("covers/{$me->id}/", $path);
        Storage::disk('public')->assertExists($path);
        Storage::disk('public')->assertMissing("covers/{$me->id}/old.jpg");
    }

    public function test_a_colleague_cannot_upload_someone_elses_cover(): void
    {
        $me = $this->person('employee');
        $them = $this->person('employee');

        $this->as($me)->post(route('employees.cover.update', $them), ['photo' => UploadedFile::fake()->image('c.jpg')])
            ->assertForbidden();
        $this->assertNull($them->fresh()->cover_path);
    }

    public function test_hr_cannot_upload_for_someone_else_but_can_remove(): void
    {
        $hr = $this->person('hr');
        $them = $this->person('employee');
        Storage::disk('public')->put("covers/{$them->id}/a.jpg", 'x');
        $them->update(['cover_path' => "covers/{$them->id}/a.jpg"]);

        $this->as($hr)->post(route('employees.cover.update', $them), ['photo' => UploadedFile::fake()->image('c.jpg')])->assertForbidden();

        $this->as($hr)->post(route('employees.cover.destroy', $them))->assertRedirect();
        $this->assertNull($them->fresh()->cover_path);
        Storage::disk('public')->assertMissing("covers/{$them->id}/a.jpg");
    }

    public function test_owner_removes_own_cover(): void
    {
        $me = $this->person('employee');
        Storage::disk('public')->put("covers/{$me->id}/a.jpg", 'x');
        $me->update(['cover_path' => "covers/{$me->id}/a.jpg"]);

        $this->as($me)->post(route('employees.cover.destroy', $me))->assertRedirect();

        $this->assertNull($me->fresh()->cover_path);
        Storage::disk('public')->assertMissing("covers/{$me->id}/a.jpg");
    }

    public function test_a_colleague_cannot_remove_someone_elses_cover(): void
    {
        $me = $this->person('employee');
        $them = $this->person('employee');
        $them->update(['cover_path' => "covers/{$them->id}/a.jpg"]);

        $this->as($me)->post(route('employees.cover.destroy', $them))->assertForbidden();
        $this->assertSame("covers/{$them->id}/a.jpg", $them->fresh()->cover_path);
    }

    public function test_another_tenants_employee_is_a_404_even_for_hr(): void
    {
        $hr = $this->person('hr');
        $otherTenant = Tenant::create(['slug' => 'beta', 'name' => 'Beta', 'initials' => 'BT']);
        $stranger = $this->person('employee', $otherTenant);

        $this->as($hr)->post(route('employees.cover.update', $stranger), ['photo' => UploadedFile::fake()->image('c.jpg')])->assertNotFound();
        $this->as($hr)->post(route('employees.cover.destroy', $stranger))->assertNotFound();
    }

    public function test_bad_uploads_are_rejected(): void
    {
        $me = $this->person('employee');

        $this->as($me)->post(route('employees.cover.update', $me), ['photo' => UploadedFile::fake()->create('big.jpg', 6000, 'image/jpeg')])->assertSessionHasErrors('photo');
        $this->as($me)->post(route('employees.cover.update', $me), ['photo' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')])->assertSessionHasErrors('photo');
        $this->as($me)->post(route('employees.cover.update', $me), [])->assertSessionHasErrors('photo');
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=ProfileCoverTest`
Expected: FAIL with route `employees.cover.update` not defined.

- [ ] **Step 3: Write the controller**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Support\ImageCompressor;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * The cover photo across the top of an employee's profile (employees.cover_path).
 *
 * Everyone who can open the profile sees it, so only the person themselves may put
 * one up. HR and management may take one down (the same people who may edit the
 * profile), which is moderation, not editing someone's picture for them.
 *
 * Route-model binding is not tenant-scoped in this app, so the tenant check comes
 * first and answers 404, never a hint that the id exists elsewhere.
 */
class EmployeeCoverController extends Controller
{
    private const DISK = 'public';

    public function update(Request $request, Employee $employee): RedirectResponse
    {
        abort_unless($employee->tenant_id === app(CurrentTenant::class)->id(), 404);
        abort_unless($employee->user_id === $request->user()->id, 403);

        $request->validate([
            'photo' => ['required', 'image', 'mimes:jpeg,jpg,png,webp', 'max:5120'],
        ]);

        $file = $request->file('photo');
        $path = $file->store('covers/'.$employee->id, self::DISK);
        abort_unless($path !== false, 500, 'Photo could not be stored.');
        ImageCompressor::compress(Storage::disk(self::DISK)->path($path), (string) $file->getMimeType());

        if ($employee->cover_path && $employee->cover_path !== $path) {
            Storage::disk(self::DISK)->delete($employee->cover_path);
        }
        $employee->update(['cover_path' => $path]);

        return back()->with('ok', 'Cover updated.');
    }

    public function destroy(Request $request, Employee $employee): RedirectResponse
    {
        abort_unless($employee->tenant_id === app(CurrentTenant::class)->id(), 404);
        abort_unless(
            $employee->user_id === $request->user()->id || $this->hasTenantRole($request, ['management', 'hr']),
            403
        );

        if ($employee->cover_path) {
            Storage::disk(self::DISK)->delete($employee->cover_path);
        }
        $employee->update(['cover_path' => null]);

        return back()->with('ok', 'Cover removed.');
    }
}
```

Check `hasTenantRole` lives on the base `Controller` (grep `function hasTenantRole app/Http/Controllers/Controller.php`). If it is on a trait instead, `use` that trait in this controller the way `BuildsPeopleData` does.

- [ ] **Step 4: Register the routes**

In `routes/web.php`, after the `employees.restore` line (~291), add:

```php
        // Profile cover photo. Owner uploads; owner or HR/management removes. See EmployeeCoverController.
        Route::post('/app/employees/{employee}/cover', [EmployeeCoverController::class, 'update'])->name('employees.cover.update');
        Route::post('/app/employees/{employee}/cover/delete', [EmployeeCoverController::class, 'destroy'])->name('employees.cover.destroy');
```

Add `use App\Http\Controllers\EmployeeCoverController;` to the imports.

- [ ] **Step 5: Run tests**

Run: `php artisan test --compact --filter=ProfileCoverTest`
Expected: PASS (7 tests).

- [ ] **Step 6: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/EmployeeCoverController.php routes/web.php tests/Feature/ProfileCoverTest.php
git commit -m "feat(profile): upload and remove a profile cover photo"
```

---

### Task 6: Cover on the profile screen

**Files:**
- Modify: `resources/views/screens/profile.blade.php` (slim card at ~line 20; the `{{-- Identity band --}}` block at line 52)
- Modify: `resources/css/app.css` (append cover styles)
- Test: `tests/Feature/ProfileCoverTest.php`

**Interfaces:**
- Consumes: `$p->cover_path`, `$isOwn`, `$canEdit`, `$canViewFull` (already in the view), routes from Task 5.
- Produces: markup `.uj-cover` with children `.uj-cover-img`, `.uj-cover-blur`, `.uj-cover-change`; the identity `.uj-card` gets `uj-card--over-cover` when a cover renders.

- [ ] **Step 1: Write the failing tests** (append inside `ProfileCoverTest`)

```php
    public function test_cover_renders_for_the_owner_a_colleague_and_on_the_slim_card(): void
    {
        $me = $this->person('employee');
        $me->update(['cover_path' => "covers/{$me->id}/a.jpg"]);
        $url = Storage::disk('public')->url("covers/{$me->id}/a.jpg");

        $own = $this->as($me)->get(route('app.screen', ['screen' => 'profile', 'emp' => $me->id]))->assertOk()->getContent();
        $this->assertStringContainsString('class="uj-cover"', $own);
        $this->assertStringContainsString($url, $own);
        $this->assertStringContainsString('Change cover', $own);
        $this->assertStringContainsString(route('employees.cover.destroy', $me), $own);

        $hr = $this->person('hr');
        $full = $this->as($hr)->get(route('app.screen', ['screen' => 'profile', 'emp' => $me->id]))->getContent();
        $this->assertStringContainsString($url, $full);
        $this->assertStringNotContainsString('Change cover', $full);
        $this->assertStringContainsString('Remove cover', $full);

        $peer = $this->person('employee');
        $slim = $this->as($peer)->get(route('app.screen', ['screen' => 'profile', 'emp' => $me->id]))->getContent();
        $this->assertStringContainsString($url, $slim);
        $this->assertStringNotContainsString('Change cover', $slim);
        $this->assertStringNotContainsString('Remove cover', $slim);
    }

    public function test_no_cover_shows_the_invitation_to_the_owner_only(): void
    {
        $me = $this->person('employee');

        $own = $this->as($me)->get(route('app.screen', ['screen' => 'profile', 'emp' => $me->id]))->getContent();
        $this->assertStringContainsString('Add a cover photo', $own);
        $this->assertStringNotContainsString('class="uj-cover"', $own);

        $hr = $this->person('hr');
        $other = $this->as($hr)->get(route('app.screen', ['screen' => 'profile', 'emp' => $me->id]))->getContent();
        $this->assertStringNotContainsString('Add a cover photo', $other);
        $this->assertStringNotContainsString('Remove cover', $other);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=ProfileCoverTest`
Expected: FAIL on `class="uj-cover"` not found.

- [ ] **Step 3: Add a shared cover partial**

Create `resources/views/partials/profile-cover.blade.php`:

```blade
{{-- Profile cover: two copies of one photo. The sharp one holds the top, the blurred
     one takes over from 30% down, both are gone before the box ends, so the picture
     dissolves into the page instead of stopping on a line. $height in px. --}}
@php $coverUrl = \Illuminate\Support\Facades\Storage::disk('public')->url($employee->cover_path); @endphp
<div class="uj-cover" style="height:{{ $height }}px;">
    <div class="uj-cover-blur" style="background-image:url('{{ $coverUrl }}');"></div>
    <div class="uj-cover-img" style="background-image:url('{{ $coverUrl }}');"></div>
    @if ($isOwn ?? false)
        <form method="post" action="{{ route('employees.cover.update', $employee) }}" enctype="multipart/form-data" x-data style="display:contents;">
            @csrf
            <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" x-ref="f" style="display:none;" @change="$el.form.requestSubmit()">
            <button type="button" class="uj-cover-change" @click="$refs.f.click()" style="backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z"></path></svg>
                <span x-text="$store.ui.lang==='en' ? 'Change cover' : 'Tukar cover'">Change cover</span>
            </button>
        </form>
    @endif
    @if (($isOwn ?? false) || ($canRemove ?? false))
        <form method="post" action="{{ route('employees.cover.destroy', $employee) }}" style="display:contents;">
            @csrf
            <button type="submit" class="uj-cover-change uj-cover-change--remove" style="backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);">
                <span x-text="$store.ui.lang==='en' ? 'Remove cover' : 'Buang cover'">Remove cover</span>
            </button>
        </form>
    @endif
</div>
```

Note: the owner's remove button must contain the exact text "Remove cover" too. The test for HR asserts "Remove cover" and not "Change cover"; the owner test asserts the destroy route URL. Both hold with this partial.

- [ ] **Step 4: Render it on both profile branches**

In `profile.blade.php`, in the slim-card branch, change the `<div class="uj-card" style="max-width:360px;padding:24px;text-align:center;">` to:

```blade
    <div class="uj-card" style="max-width:360px;padding:0 24px 24px;text-align:center;overflow:hidden;">
        @if ($p->cover_path)
            <div style="margin:0 -24px 14px;">@include('partials.profile-cover', ['employee' => $p, 'height' => 120, 'isOwn' => false, 'canRemove' => false])</div>
        @else
            <div style="height:24px;"></div>
        @endif
```

(The original avatar and the rest of the slim card follow unchanged.)

In the full branch, replace the `{{-- Identity band --}}` opening line and its `<div class="uj-card" style="padding:24px;display:flex;…">` with:

```blade
        {{-- Cover + identity band. With a cover the band rides up over its lower third. --}}
        @if ($p->cover_path)
            @include('partials.profile-cover', ['employee' => $p, 'height' => 200, 'isOwn' => $isOwn, 'canRemove' => $canEdit])
        @elseif ($isOwn)
            <form method="post" action="{{ route('employees.cover.update', $p) }}" enctype="multipart/form-data" x-data>
                @csrf
                <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" x-ref="f" style="display:none;" @change="$el.form.requestSubmit()">
                <button type="button" class="uj-cover-invite" @click="$refs.f.click()">
                    + <span x-text="$store.ui.lang==='en' ? 'Add a cover photo' : 'Tambah foto cover'">Add a cover photo</span>
                </button>
                @error('photo')<p style="margin:6px 0 0;font-size:12px;color:var(--error);">{{ $message }}</p>@enderror
            </form>
        @endif
        <div class="uj-card {{ $p->cover_path ? 'uj-card--over-cover' : '' }}" style="padding:24px;display:flex;align-items:center;gap:20px;flex-wrap:wrap;">
```

And on the avatar disc inside that band, add `class="uj-avatar-ring"` when a cover is present (append `{{ $p->cover_path ? 'uj-avatar-ring' : '' }}` as its class attribute).

- [ ] **Step 5: Add the CSS**

Append to `resources/css/app.css`:

```css
/* ── Profile cover ──
   Sharp on top, a blurred copy carries the colour down, both gone before the box
   ends. Masks, not JS. The blur is a filter on an element, so it survives the
   Lightning CSS pass that eats backdrop-filter (see .uj-hd-fade). */
.uj-cover { position: relative; overflow: hidden; margin: 0 0 -64px; border-radius: 12px 12px 0 0; }
.uj-cover-img, .uj-cover-blur { position: absolute; inset: 0; background-size: cover; background-position: center; }
.uj-cover-img { mask-image: linear-gradient(to bottom, #000 35%, transparent 80%); -webkit-mask-image: linear-gradient(to bottom, #000 35%, transparent 80%); }
.uj-cover-blur { filter: blur(18px); transform: scale(1.08); mask-image: linear-gradient(to bottom, transparent 30%, #000 60%, transparent 88%); -webkit-mask-image: linear-gradient(to bottom, transparent 30%, #000 60%, transparent 88%); }
.uj-cover-change { position: absolute; top: 14px; right: 14px; height: 30px; padding: 0 11px; border-radius: 7px; font-size: 11.5px; font-weight: 500; display: inline-flex; align-items: center; gap: 6px; color: var(--ink); background: color-mix(in srgb, var(--canvas) 78%, transparent); border: 1px solid rgba(255, 255, 255, .5); cursor: pointer; transition: transform 160ms cubic-bezier(.23, 1, .32, 1); }
.uj-cover-change:active { transform: scale(.97); }
.uj-cover-change--remove { top: auto; bottom: 78px; }
.uj-cover-change + form .uj-cover-change--remove, form + form .uj-cover-change--remove { right: 14px; }
.uj-card--over-cover { position: relative; z-index: 1; margin: 0 24px; box-shadow: 0 8px 24px -12px rgba(38, 37, 30, .28); }
.uj-avatar-ring { box-shadow: 0 0 0 4px #fff; }
.uj-cover-invite { width: 100%; height: 64px; border: 1px dashed var(--shelf-line); border-radius: 10px; background: none; color: var(--muted); font-size: 12.5px; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 6px; transition: transform 160ms cubic-bezier(.23, 1, .32, 1), border-color 160ms ease; }
.uj-cover-invite:hover { border-color: var(--muted-soft); color: var(--ink); }
.uj-cover-invite:active { transform: scale(.99); }
@media (max-width: 640px) {
    .uj-cover { height: 150px !important; margin-bottom: -54px; }
    .uj-card--over-cover { margin: 0 12px; }
    .uj-cover-change span { display: none; }
    .uj-cover-change { padding: 0 8px; }
    .uj-cover-change--remove span { display: inline; }
}
```

When both "Change cover" and "Remove cover" show for the owner, the remove pill sits at the bottom-right of the cover, above where the identity card overlaps (`bottom: 78px`). Adjust after looking; a stacked pair at top-right (`top: 50px`) is also acceptable.

- [ ] **Step 6: Run tests and look at it**

Run: `php artisan test --compact --filter=ProfileCoverTest` → PASS (9 tests).
With `bun run dev`: upload a wide photo on your own profile, confirm the fade reaches canvas before the identity card, the card floats with a shadow, the avatar has a white ring, "Remove cover" works, and a colleague's profile shows no controls. Check the slim card as a plain employee viewing someone else. Check a phone width.

- [ ] **Step 7: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/partials/profile-cover.blade.php resources/views/screens/profile.blade.php resources/css/app.css tests/Feature/ProfileCoverTest.php
git commit -m "feat(profile): cover photo across the top of the profile, blurring and fading into the page"
```

---

### Task 7: Changelog, assets, full suite

**Files:**
- Modify: `resources/changelog.yaml` (new release at the top)
- Modify: `public/build/**` (rebuilt)
- Modify: `tests/Feature/ChangelogScreenTest.php` only if it asserts the latest version string (check with `grep -n "1.7.4" tests/Feature/ChangelogScreenTest.php`)

- [ ] **Step 1: Add the release entry** at the top of `resources/changelog.yaml`, above `- version: "1.7.4"`:

```yaml
- version: "1.8"
  date: "2026-09-03"
  entries:
    - tag: added
      major: true
      text: "Make Amanahku yours: pick a background for your workspace, or upload your own photo, from Account & security → Appearance. Only you see it.\nYou can also put a cover photo across the top of your profile, which everyone who opens your profile sees."
      text_ms: "Jadikan Amanahku milik anda: pilih latar belakang untuk ruang kerja anda, atau muat naik foto sendiri, dari Akaun & keselamatan → Penampilan. Hanya anda yang melihatnya.\nAnda juga boleh letak foto cover di bahagian atas profil anda, yang dilihat semua orang yang membuka profil anda."
      link: "/app/security#appearance"
      link_text: "Choose a background"
      link_text_ms: "Pilih latar belakang"
```

- [ ] **Step 2: Run the changelog test**

Run: `php artisan test --compact tests/Feature/ChangelogScreenTest.php`
Expected: PASS. If it pins the version string, update that assertion to `1.8`.

- [ ] **Step 3: Rebuild assets from a clean tree**

```bash
git status --short          # must be empty apart from changelog.yaml
php artisan view:clear && php artisan view:cache
bun run build
git add resources/changelog.yaml public/build
git commit -m "chore(release): 1.8 custom backgrounds, rebuilt assets"
```

- [ ] **Step 4: Run the full suite**

Run: `php artisan test --compact`
Expected: all green. Fix anything the new columns or layout change broke (a snapshot-style test asserting the exact header markup is the likely candidate) and amend that fix into a separate commit.

- [ ] **Step 5: Manual detector pass on the changed UI**

```bash
node /home/shzwn/.claude-work/plugins/cache/impeccable/impeccable/4.0.2/skills/impeccable/scripts/detect.mjs --json resources/views/screens/security.blade.php resources/views/partials/profile-cover.blade.php resources/views/screens/profile.blade.php resources/css/app.css
```

Fix real findings; leave the single-font finding, the app pairs Poppins with JetBrains Mono by design.

---

## Self-review

- Spec coverage: presets + upload + dim (T1, T2, T4); wallpaper layers, header, plate, mobile dim step (T3); account-menu row (T4); cover upload/remove, permissions, tenant 404 (T5); cover render on full + slim, invitation, fade values, avatar ring, phone height (T6); changelog and assets (T7). Not in scope items untouched.
- Placeholders: none.
- Names consistent: `account.appearance`, `account.appearance.photo.destroy`, `employees.cover.update`, `employees.cover.destroy`, `users.appearance`, `employees.cover_path`, `.uj-wallpaper`, `.uj-plate`, `.uj-cover*`, `uj-has-wallpaper`.
