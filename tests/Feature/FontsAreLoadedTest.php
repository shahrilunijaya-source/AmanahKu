<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Poppins and JetBrains Mono are self-hosted through the Vite fonts plugin, which
 * emits their @font-face rules as a NON-ENTRY chunk (`_fonts-*.css`). The `@vite`
 * directive only links entry points, so nothing referenced them and every page in
 * the app quietly rendered in the system UI font instead. It looked fine, which is
 * why it survived: the only symptom is that the type is subtly wrong everywhere.
 *
 * `Vite::fonts()` is what emits the link. These tests fail the moment a page is
 * added without it, or the weights the design depends on are dropped.
 */
class FontsAreLoadedTest extends TestCase
{
    /** Weights the type ramp actually asks for. Mono 600 carries counts and the clock. */
    private const REQUIRED = [
        'Poppins' => [400, 500, 600, 700],
        'JetBrains Mono' => [400, 500, 600],
    ];

    /** @return list<string> every Blade file that pulls in the compiled assets */
    private function bladesLoadingAssets(): array
    {
        return collect(File::allFiles(resource_path('views')))
            ->filter(fn ($f) => str_contains($f->getContents(), '@vite('))
            ->map(fn ($f) => str_replace(base_path().'/', '', $f->getPathname()))
            ->values()->all();
    }

    public function test_every_page_that_loads_assets_also_loads_the_fonts(): void
    {
        $blades = $this->bladesLoadingAssets();

        $this->assertNotEmpty($blades, 'Found no Blade files calling @vite — the scan is broken, not the app.');

        $missing = array_values(array_filter(
            $blades,
            fn ($b) => ! str_contains(File::get(base_path($b)), 'Vite::fonts()')
        ));

        $this->assertSame([], $missing, sprintf(
            "These pages load the compiled CSS but never link the @font-face rules, so they\n"
            ."render in the system font: %s\nAdd {{ Vite::fonts() }} above the @vite call.",
            implode(', ', $missing)
        ));
    }

    public function test_the_build_ships_every_weight_the_design_uses(): void
    {
        $manifest = public_path('build/fonts-manifest.json');

        $this->assertFileExists($manifest, 'No fonts manifest. Run `bun run build`.');

        $css = json_decode(File::get($manifest), true)['style']['familyStyles'] ?? [];
        $this->assertNotEmpty($css, 'The fonts manifest declares no families.');

        $all = implode("\n", $css);

        foreach (self::REQUIRED as $family => $weights) {
            foreach ($weights as $weight) {
                $this->assertMatchesRegularExpression(
                    '/font-family:\s*"'.preg_quote($family, '/').'";[^}]*font-weight:\s*'.$weight.'\b/s',
                    $all,
                    "$family $weight is not in the build. The browser will fake it by smearing "
                    .'a neighbouring weight. Add it to the `fonts` block in vite.config.js.'
                );
            }
        }
    }
}
