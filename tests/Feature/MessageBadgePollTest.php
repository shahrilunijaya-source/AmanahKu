<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The unread-messages badge polls the server from every open tab. Both guards below are
 * load protection, not behaviour, so nothing in the UI breaks when they're removed —
 * which is exactly how the fixed 5s interval survived until a production profile
 * (2026-08-12) caught a background tab still hitting the route every 5 seconds. Pinned
 * here so a rewrite of the store has to delete a passing test to bring the storm back.
 */
class MessageBadgePollTest extends TestCase
{
    public function test_the_badge_poll_skips_hidden_tabs_and_jitters_its_interval(): void
    {
        $layout = (string) file_get_contents(resource_path('views/layouts/app.blade.php'));

        $store = $this->msgbadgeStore($layout);

        $this->assertStringContainsString('if (document.hidden) return;', $store);
        $this->assertStringContainsString('Math.random()', $store);
        $this->assertStringNotContainsString('setInterval', $store);
    }

    /**
     * Narrow the layout down to the msgbadge store, cut at the @endif that closes its
     * feature flag. Anything looser reaches notifbell, which carries the same two guards
     * and a comment mentioning setInterval, and would answer every assertion here.
     */
    private function msgbadgeStore(string $layout): string
    {
        $start = strpos($layout, "Alpine.store('msgbadge'");
        $this->assertNotFalse($start, 'msgbadge store is gone from the app layout.');

        $end = strpos($layout, '@endif', $start);
        $this->assertNotFalse($end, 'msgbadge store is no longer inside a feature-flag block.');

        return substr($layout, $start, $end - $start);
    }
}
