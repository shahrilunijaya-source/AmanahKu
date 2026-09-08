<?php

namespace Tests;

use App\Models\PlatformFeature;
use App\Services\FeatureManager;
use App\Support\Features;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
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

        // bootstrap/app.php's withSchedule() closure only runs once this test's console
        // kernel has bootstrapped (Illuminate\Console\Application's constructor, triggered
        // by any Artisan::call). RefreshDatabase only forces that for the very first test
        // in the whole run (its migrate:fresh is skipped on every later test), so a test
        // that reads app(Schedule::class)->events() without calling an Artisan command of
        // its own first sees an empty schedule purely by test order (docs/build/OPEN.md
        // S15/CR-17). schedule:list is a harmless read; this warms the hook for everyone.
        //
        // For the one test in the whole run where this warm-up is the very first Artisan
        // call (RefreshDatabase's own migrate:fresh included), Console\Application ends up
        // constructed twice inside that single call, and the second construction replays
        // the withSchedule() closure onto the now-already-resolved Schedule singleton — the
        // whole bootstrap/app.php schedule gets registered onto it twice (docs/build/OPEN.md
        // S16/CR-34). Rather than depend on that internal double-construction (which a
        // framework upgrade could change), de-duplicate by command+expression afterwards:
        // harmless when nothing doubled, fixes it when it did.
        Artisan::call('schedule:list');
        $schedule = app(Schedule::class);
        $seen = [];
        $unique = array_values(array_filter($schedule->events(), function ($event) use (&$seen) {
            $key = $event->command.'|'.$event->getExpression();
            if (isset($seen[$key])) {
                return false;
            }
            $seen[$key] = true;

            return true;
        }));
        (function () use ($unique) {
            $this->events = $unique;
        })->call($schedule);

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
