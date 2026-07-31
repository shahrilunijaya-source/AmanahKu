<?php

declare(strict_types=1);

namespace App\Attendance;

/**
 * The site an employee is expected to clock from on a given day, with its geofence and
 * working hours resolved. Immutable — produced by ScheduleResolver, consumed by ClockService
 * and the attendance screen.
 */
final class SiteSpec
{
    public function __construct(
        public readonly string $type,        // office | client | home
        public readonly string $label,
        public readonly ?float $latitude,
        public readonly ?float $longitude,
        public readonly int $radiusM,
        public readonly ?string $workStart,  // 'H:i' or null when not configured
        public readonly ?string $workEnd,
        public readonly ?float $minHours,
        public readonly bool $needsHomeCapture = false, // home day but no home coords registered yet
    ) {}

    /** True when this site has coordinates to geofence against. */
    public function hasGeofence(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /** Attendance-record `type` enum value for this site. */
    public function attendanceType(): string
    {
        return match ($this->type) {
            'home' => 'wfh',
            'client' => 'client',
            default => 'standard',
        };
    }
}
