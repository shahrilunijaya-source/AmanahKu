<?php

namespace Tests\Feature;

use App\Http\Middleware\DevClock;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DevClockTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_session_clock_pins_now_only_when_local(): void
    {
        $request = Request::create('/');
        $request->setLaravelSession(app('session.store'));
        $request->session()->put('dev_now', '2026-10-03 09:00:00');

        app()->detectEnvironment(fn () => 'production');
        (new DevClock)->handle($request, fn () => response(''));
        $this->assertFalse(Carbon::hasTestNow());

        app()->detectEnvironment(fn () => 'local');
        (new DevClock)->handle($request, fn () => response(''));
        $this->assertSame('2026-10-03 09:00:00', now()->toDateTimeString());
    }
}
