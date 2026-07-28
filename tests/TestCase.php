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
     * Features::OFF. Those modules are descoped from the shipped app, but their code
     * is still here and must keep working for the day a company switches one back on
     * — so their tests keep running against the real routes and screens.
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
