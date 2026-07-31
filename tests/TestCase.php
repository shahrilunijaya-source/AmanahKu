<?php

namespace Tests;

use App\Models\PlatformFeature;
use App\Services\FeatureManager;
use App\Support\Features;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Schema;

abstract class TestCase extends BaseTestCase
{
    /**
     * The suite runs with every module switched ON, including the ones in
     * Features::OFF. Those modules are descoped from the shipped app, but their
     * models, controllers, and routes are still here and must keep working for the
     * day a company switches one back on — so their tests keep running.
     *
     * Their **screen blades were deleted** in the UI revamp, so a descoped module now
     * renders screens.empty (AppController's View::exists fallback), not its own UI.
     * The handful of tests that asserted markup inside those blades are
     * markTestSkipped, not deleted. Reviving a module means restoring its blade first:
     * `git checkout pre-blade-purge -- resources/views/screens/<screen>.blade.php`.
     *
     * This writes an unlocked platform row per OFF key, which outranks the registry
     * default without changing what a real tenant gets. A test that asserts gating
     * behaviour calls useShippedModuleDefaults() to see the production defaults.
     */
    protected function setUp(): void
    {
        parent::setUp();

        if (Schema::hasTable('platform_features')) {
            PlatformFeature::upsert(
                array_map(fn (string $key) => ['key' => $key, 'value' => '1', 'locked' => false], Features::OFF),
                ['key'],
                ['value', 'locked'],
            );
        }
    }

    /** Drop the suite-wide overrides so Features::OFF resolves off, as it does in production. */
    protected function useShippedModuleDefaults(): void
    {
        PlatformFeature::whereIn('key', Features::OFF)->delete();

        // FeatureManager is a singleton and memoises the platform table on first read.
        app()->forgetInstance(FeatureManager::class);
    }
}
