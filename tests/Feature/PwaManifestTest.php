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

    public function test_the_shared_partial_links_the_manifest_and_apple_touch_icon(): void
    {
        $partial = (string) file_get_contents(resource_path('views/partials/pwa-head.blade.php'));

        $this->assertStringContainsString('rel="manifest"', $partial);
        $this->assertStringContainsString('apple-touch-icon', $partial);
    }

    /**
     * A user can add the app to the Home Screen from whatever page is open, and the browser
     * reads the icon only from that page. The login screen is the likeliest one of all, and
     * it does not use the app layout, so every view with its own <head> must pull the partial.
     */
    public function test_every_view_with_its_own_head_pulls_the_pwa_partial(): void
    {
        $missing = [];

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views')));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if (str_contains($source, '</head>') && ! str_contains($source, 'partials.pwa-head')) {
                $missing[] = $file->getPathname();
            }
        }

        $this->assertSame([], $missing, 'These views render a <head> without the PWA tags: '.implode(', ', $missing));
    }

    public function test_the_login_page_carries_the_install_icon(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
        $response->assertSee('rel="manifest"', false);
        $response->assertSee('apple-touch-icon', false);
    }
}
