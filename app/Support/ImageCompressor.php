<?php

namespace App\Support;

/**
 * Server-side compression for uploaded Knowledge Bank pictures. No new dependency — the
 * `gd` PHP extension is already loaded. Resizes only if the longest side exceeds 2000px
 * (aspect ratio preserved) and re-encodes: JPEG/WebP at quality 82 (visually near-lossless,
 * typically 60-80% smaller than a phone-camera original), PNG losslessly at zlib level 6.
 * GIFs are left untouched — a GD re-encode would drop animation frames.
 *
 * ponytail: GD is the smallest thing that gets real size reduction without a new Composer
 * dependency. If quality complaints come in, Imagick (also already loaded) or Intervention
 * Image is a contained swap inside this one class, not a rewrite.
 */
class ImageCompressor
{
    private const MAX_DIMENSION = 2000;

    private const QUALITY = 82;

    public static function compress(string $absolutePath, string $mime): void
    {
        $image = match ($mime) {
            'image/jpeg', 'image/jpg' => @imagecreatefromjpeg($absolutePath),
            'image/png' => @imagecreatefrompng($absolutePath),
            'image/webp' => @imagecreatefromwebp($absolutePath),
            default => null,
        };

        if ($image === null || $image === false) {
            return;
        }

        $width = imagesx($image);
        $height = imagesy($image);

        if (max($width, $height) > self::MAX_DIMENSION) {
            $scale = self::MAX_DIMENSION / max($width, $height);
            $newWidth = (int) round($width * $scale);
            $newHeight = (int) round($height * $scale);

            $resized = imagecreatetruecolor($newWidth, $newHeight);
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            imagedestroy($image);
            $image = $resized;
        }

        match ($mime) {
            'image/jpeg', 'image/jpg' => imagejpeg($image, $absolutePath, self::QUALITY),
            'image/png' => imagepng($image, $absolutePath, 6),
            'image/webp' => imagewebp($image, $absolutePath, self::QUALITY),
            default => null,
        };

        imagedestroy($image);
    }
}
