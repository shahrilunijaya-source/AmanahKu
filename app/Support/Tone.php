<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Is a background dark enough that the text over it must go white?
 *
 * One number per background, WCAG relative luminance 0..1: a gradient preset is
 * the mean of its hex stops, an uploaded photo is the mean of a 16×16 resample
 * taken once at upload time and stored beside the path. The wallpaper's dim
 * lays page canvas over the picture, so the effective luminance is the blend.
 *
 * ponytail: one mean per picture, no top/bottom split. A photo with a black sky
 * over a white beach averages to mid-grey and lands wherever the threshold says;
 * sample the top band separately if that ever bites.
 */
class Tone
{
    /** Relative luminance of --canvas (#f6f6f3), what the dim mixes in. */
    public const CANVAS = 0.92;

    /** Below this effective luminance the text over the background turns white. */
    public const DARK_BELOW = 0.45;

    /** Mean relative luminance of every #rrggbb in a CSS gradient string, or null if none. */
    public static function ofCss(string $css): ?float
    {
        preg_match_all('/#([0-9a-f]{6})\b/i', $css, $m);
        if ($m[1] === []) {
            return null;
        }
        $sum = 0.0;
        foreach ($m[1] as $hex) {
            $sum += self::ofRgb(hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)));
        }

        return round($sum / count($m[1]), 3);
    }

    /** Mean relative luminance of an image file on disk, or null if GD cannot read it. */
    public static function ofImage(string $absolutePath, string $mime): ?float
    {
        $image = ImageCompressor::open($absolutePath, $mime);
        if ($image === null) {
            return null;
        }
        $n = 16;
        $small = imagecreatetruecolor($n, $n);
        imagecopyresampled($small, $image, 0, 0, 0, 0, $n, $n, imagesx($image), imagesy($image));

        $sum = 0.0;
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                $rgb = imagecolorat($small, $x, $y);
                $sum += self::ofRgb(($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF);
            }
        }

        return round($sum / ($n * $n), 3);
    }

    /** True when the background, with `$dimPct` percent of canvas laid over it, reads as dark. */
    public static function isDark(?float $luminance, int $dimPct = 0): bool
    {
        if ($luminance === null) {
            return false;
        }
        $d = max(0, min(100, $dimPct)) / 100;

        return $luminance * (1 - $d) + self::CANVAS * $d < self::DARK_BELOW;
    }

    /** WCAG 2 relative luminance of one sRGB colour. */
    public static function ofRgb(int $r, int $g, int $b): float
    {
        $lin = static function (int $c): float {
            $s = $c / 255;

            return $s <= 0.03928 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $lin($r) + 0.7152 * $lin($g) + 0.0722 * $lin($b);
    }
}
