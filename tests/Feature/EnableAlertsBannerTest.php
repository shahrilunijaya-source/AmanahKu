<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The first-visit banner is the discoverable path to the same permission ask as the bell
 * dropdown's "Turn on alerts" button. It must bind to the shared alerts store (not spin up
 * its own Alpine.data() component, which would mean a second setInterval poll), persist its
 * own dismissal separately from the permission state, and actually be wired into the layout.
 */
class EnableAlertsBannerTest extends TestCase
{
    public function test_banner_binds_to_the_shared_alerts_store_and_persists_dismissal(): void
    {
        $partial = (string) file_get_contents(resource_path('views/partials/enable-alerts.blade.php'));

        $this->assertStringContainsString('$store.alerts.enable()', $partial);
        $this->assertStringContainsString('amanahku:alertsBannerDismissed', $partial);
        $this->assertStringContainsString('role="status"', $partial);
    }

    public function test_layout_includes_the_banner(): void
    {
        $layout = (string) file_get_contents(resource_path('views/layouts/app.blade.php'));

        $this->assertStringContainsString("@include('partials.enable-alerts')", $layout);
    }
}
