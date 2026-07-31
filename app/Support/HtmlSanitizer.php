<?php

declare(strict_types=1);

namespace App\Support;

use HTMLPurifier;
use HTMLPurifier_Config;

/**
 * Server-side sanitiser for rich-text (Quill) HTML. This is the security
 * boundary: client HTML is never trusted. strip_tags is NOT enough — it leaves
 * event handlers (onclick) and javascript: URLs intact — so we run HTMLPurifier
 * with a tight allow-list matching the timesheet description toolbar.
 */
class HtmlSanitizer
{
    private const ALLOWED = 'p,br,strong,b,em,i,u,s,ul,ol,li,a[href|title],h3,blockquote';

    private static ?HTMLPurifier $purifier = null;

    public static function clean(?string $raw): ?string
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }

        $clean = trim(self::purifier()->purify($raw));

        // Treat an editor that only emitted empty markup (e.g. "<p><br></p>") as no description.
        return strip_tags($clean) === '' && ! str_contains($clean, '<img') ? null : $clean;
    }

    private static function purifier(): HTMLPurifier
    {
        if (self::$purifier instanceof HTMLPurifier) {
            return self::$purifier;
        }

        $config = HTMLPurifier_Config::createDefault();
        $config->set('Core.Encoding', 'UTF-8');
        $config->set('HTML.Allowed', self::ALLOWED);
        $config->set('HTML.TargetBlank', true);        // external links open safely
        $config->set('AutoFormat.RemoveEmpty', true);
        $config->set('AutoFormat.AutoParagraph', false);

        // HTMLPurifier serialises its definition cache to disk; keep it inside storage
        // so it is writable in every environment.
        $cacheDir = storage_path('app/htmlpurifier');
        if (! is_dir($cacheDir)) {
            @mkdir($cacheDir, 0o775, true);
        }
        $config->set('Cache.SerializerPath', $cacheDir);

        return self::$purifier = new HTMLPurifier($config);
    }
}
