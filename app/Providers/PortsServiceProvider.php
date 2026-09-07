<?php

namespace App\Providers;

use App\Ports\CalendarPort;
use App\Ports\MailPort;
use App\Ports\Stub\StubCalendarPort;
use App\Ports\Stub\StubMailPort;
use App\Ports\Stub\StubTrackPort;
use App\Ports\TrackPort;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

/**
 * Binds the three ports (docs/build/contracts/ports.md) from `config('ports.driver')`.
 * During the build run `stub` is the only driver that exists: any other name logs a
 * warning and still resolves to the stub, so an env variable alone cannot switch a real
 * adapter on. Real adapters get added to REAL after the run, one per port.
 */
class PortsServiceProvider extends ServiceProvider
{
    /** @var array<string, class-string> */
    private const STUB = [
        'calendar' => StubCalendarPort::class,
        'track' => StubTrackPort::class,
        'mail' => StubMailPort::class,
    ];

    /** @var array<string, class-string> */
    private const PORT = [
        'calendar' => CalendarPort::class,
        'track' => TrackPort::class,
        'mail' => MailPort::class,
    ];

    public function register(): void
    {
        foreach (self::PORT as $port => $interface) {
            $this->app->singleton($interface, function () use ($port) {
                $driver = (string) config("ports.driver.{$port}", 'stub');
                $class = $driver === 'stub' ? self::STUB[$port] : (self::realAdapters()["{$port}:{$driver}"] ?? null);
                if ($class === null) {
                    Log::warning("Port {$port}: driver '{$driver}' is not enabled on this install, using the stub.");
                    $class = self::STUB[$port];
                }

                return $this->app->make($class);
            });
        }
    }

    /**
     * Real adapters keyed "<port>:<driver>", e.g. "calendar:google" => GoogleCalendarAdapter::class.
     * Empty during the build run on purpose; Shazwan adds one entry per port after it.
     *
     * @return array<string, class-string>
     */
    private static function realAdapters(): array
    {
        return [];
    }
}
