<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The app shell's chrome contract: the header/sidebar revamp put three things in
 * place that are easy to undo by accident, because each of them looks like a
 * cosmetic detail in a diff.
 *
 *  - The header's fade edge carries its blur as an INLINE style. It cannot live in
 *    app.css: Lightning CSS (Tailwind v4's pipeline) rewrites `backdrop-filter`
 *    there to the -webkit- prefix alone and drops the standard property, which the
 *    browsers we target do not implement, so the effect dies silently in the build
 *    while still working when the file is opened directly.
 *  - Type comes from the five-step ramp. A stray `font-size:13px` renders fine and
 *    is invisible in review, which is exactly why it needs a mechanical check.
 *  - The language switcher is a neutral segmented control, not a red-filled tab.
 */
class ShellChromeTest extends TestCase
{
    use RefreshDatabase;

    /** Blades that make up the shell and must therefore stay on the ramp. */
    private const SHELL_BLADES = [
        'resources/views/layouts/app.blade.php',
        'resources/views/partials/header.blade.php',
        'resources/views/partials/sidebar.blade.php',
        'resources/views/partials/env-badge.blade.php',
    ];

    private function signIn(): User
    {
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme', 'initials' => 'AC']);
        $user = User::create(['name' => 'Ana', 'email' => 'ana@acme.test', 'password' => Hash::make('password')]);
        $user->tenants()->attach($tenant->id, ['role' => 'hr']);

        $this->actingAs($user)->withSession(['current_tenant' => $tenant->id]);

        return $user;
    }

    public function test_header_fade_keeps_its_blur_inline(): void
    {
        $this->signIn();

        $html = $this->get('/app/dash')->assertOk()->getContent();

        $this->assertStringContainsString('class="uj-hd-fade"', $html);
        $this->assertMatchesRegularExpression(
            '/uj-hd-fade"[^>]*style="[^"]*(?<!-webkit-)backdrop-filter:\s*blur\(/',
            $html,
            'The fade edge lost its inline backdrop-filter. In app.css the build strips the '
            .'standard property and keeps only -webkit-, so the blur disappears in production.'
        );
    }

    public function test_language_switcher_is_a_neutral_segmented_control(): void
    {
        $this->signIn();

        $html = $this->get('/app/dash')->assertOk()->getContent();

        $this->assertStringContainsString('uj-seg', $html);
        $this->assertStringNotContainsString(
            "\$store.ui.lang==='en'?'var(--red)'", $html,
            'The language tabs went back to a red fill. Red is the primary action colour, '
            .'not the "which tab am I on" colour.'
        );
    }

    public function test_shell_blades_only_use_the_type_ramp(): void
    {
        foreach (self::SHELL_BLADES as $blade) {
            $source = file_get_contents(base_path($blade));

            preg_match_all('/font(?:-size)?:\s*\d+(?:\.\d+)?px/', $source, $hits);

            $this->assertSame([], $hits[0], sprintf(
                '%s sets a raw pixel font size (%s). Use --t-micro / --t-sm / --t-base / '
                .'--t-lg / --t-xl so the shell and the dashboard stay one system.',
                $blade,
                implode(', ', array_unique($hits[0]))
            ));
        }
    }
}
