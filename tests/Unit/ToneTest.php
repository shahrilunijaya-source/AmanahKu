<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Tone;
use PHPUnit\Framework\TestCase;

class ToneTest extends TestCase
{
    public function test_luminance_of_black_and_white(): void
    {
        $this->assertSame(0.0, Tone::ofRgb(0, 0, 0));
        $this->assertEqualsWithDelta(1.0, Tone::ofRgb(255, 255, 255), 0.001);
    }

    public function test_css_gradient_averages_its_hex_stops(): void
    {
        $this->assertEqualsWithDelta(0.5, Tone::ofCss('linear-gradient(#000000, #ffffff)'), 0.001);
        $this->assertNull(Tone::ofCss('url(/x.jpg)'));
    }

    public function test_dim_lays_canvas_over_a_dark_picture(): void
    {
        $this->assertTrue(Tone::isDark(0.1));
        $this->assertTrue(Tone::isDark(0.1, 30));
        $this->assertFalse(Tone::isDark(0.1, 55));
        $this->assertFalse(Tone::isDark(0.8));
        $this->assertFalse(Tone::isDark(null));
    }

    public function test_presets_split_as_designed(): void
    {
        $presets = require __DIR__.'/../../config/amanahku.php';
        $dark = [];
        foreach ($presets['wallpaper_presets'] as $key => $css) {
            if (Tone::isDark(Tone::ofCss($css), 30)) {
                $dark[] = $key;
            }
        }
        $this->assertSame(['dusk', 'slate'], $dark);
    }

    public function test_image_luminance_is_sampled_from_the_pixels(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'tone').'.png';
        $img = imagecreatetruecolor(40, 40);
        imagefill($img, 0, 0, imagecolorallocate($img, 20, 20, 20));
        imagepng($img, $path);

        $this->assertLessThan(0.05, Tone::ofImage($path, 'image/png'));
        $this->assertNull(Tone::ofImage($path, 'application/pdf'));
        unlink($path);
    }
}
