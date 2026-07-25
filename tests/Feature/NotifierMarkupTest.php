<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The bell must carry the Alpine hooks the notifier binds to. A rename on either side
 * silently breaks browser alerts with no error anywhere, so the contract is pinned here.
 */
class NotifierMarkupTest extends TestCase
{
    public function test_bell_mounts_the_notifier_component(): void
    {
        $header = (string) file_get_contents(resource_path('views/partials/header.blade.php'));

        $this->assertStringContainsString('x-data="notifier"', $header);
        $this->assertStringContainsString('x-show="canAsk"', $header);
        $this->assertStringContainsString('@click="enable()"', $header);
    }

    public function test_notifier_module_is_registered_in_the_bundle(): void
    {
        $appJs = (string) file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString("import { registerNotifier } from './notifier'", $appJs);
        $this->assertStringContainsString('registerNotifier(Alpine)', $appJs);
    }

    /**
     * The Blade half of the contract (asserted above) binds to specific names inside
     * notifier.js. Neither test alone catches a rename: this one pins the JS side, so
     * renaming the registration key, the canAsk getter, or enable() fails a test instead
     * of silently breaking every alert with no console error.
     */
    public function test_notifier_module_exposes_the_hooks_the_bell_binds_to(): void
    {
        $notifierJs = (string) file_get_contents(resource_path('js/notifier.js'));

        $this->assertStringContainsString("Alpine.data('notifier'", $notifierJs);
        $this->assertStringContainsString('canAsk', $notifierJs);
        $this->assertStringContainsString('enable()', $notifierJs);
    }
}
