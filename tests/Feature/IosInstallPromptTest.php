<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The banner must only target iOS Safari outside standalone mode, and must be
 * dismissible — an undismissable nag on every page load is worse than no banner.
 */
class IosInstallPromptTest extends TestCase
{
    public function test_banner_targets_ios_outside_standalone_and_can_be_dismissed(): void
    {
        $partial = (string) file_get_contents(resource_path('views/partials/ios-install.blade.php'));

        $this->assertStringContainsString('navigator.standalone', $partial);
        $this->assertStringContainsString('amanahku:iosInstallDismissed', $partial);
        $this->assertStringContainsString('dismiss()', $partial);
    }

    public function test_layout_includes_the_banner(): void
    {
        $layout = (string) file_get_contents(resource_path('views/layouts/app.blade.php'));

        $this->assertStringContainsString("@include('partials.ios-install')", $layout);
    }
}
