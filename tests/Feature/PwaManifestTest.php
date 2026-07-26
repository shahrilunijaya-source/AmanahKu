<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The manifest and icons are static files served by nginx, so they are asserted on disk
 * rather than through the HTTP kernel. Installability is a hard prerequisite for
 * notifications on iOS, and a missing icon silently kills the install prompt.
 */
class PwaManifestTest extends TestCase
{
    public function test_manifest_declares_everything_an_install_prompt_needs(): void
    {
        $path = public_path('manifest.webmanifest');
        $this->assertFileExists($path);

        $manifest = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('Amanahku', $manifest['name']);
        $this->assertSame('Amanahku', $manifest['short_name']);
        $this->assertSame('/app', $manifest['start_url']);
        $this->assertSame('standalone', $manifest['display']);
        $this->assertNotEmpty($manifest['icons']);

        $sizes = array_column($manifest['icons'], 'sizes');
        $this->assertContains('192x192', $sizes);
        $this->assertContains('512x512', $sizes);
    }

    public function test_every_declared_icon_exists_on_disk(): void
    {
        $manifest = json_decode((string) file_get_contents(public_path('manifest.webmanifest')), true, flags: JSON_THROW_ON_ERROR);

        foreach ($manifest['icons'] as $icon) {
            $this->assertFileExists(public_path(ltrim($icon['src'], '/')), "Missing icon {$icon['src']}");
        }

        $this->assertFileExists(public_path('icons/apple-touch-icon.png'));
    }

    public function test_service_worker_handles_notification_clicks(): void
    {
        $path = public_path('sw.js');
        $this->assertFileExists($path);

        $source = (string) file_get_contents($path);
        $this->assertStringContainsString('notificationclick', $source);
        // A fetch listener must be declared or Chrome will not offer "Install app".
        $this->assertStringContainsString("addEventListener('fetch'", $source);
    }

    public function test_layout_links_the_manifest_and_apple_touch_icon(): void
    {
        $layout = (string) file_get_contents(resource_path('views/layouts/app.blade.php'));

        $this->assertStringContainsString('rel="manifest"', $layout);
        $this->assertStringContainsString('apple-touch-icon', $layout);
    }
}
