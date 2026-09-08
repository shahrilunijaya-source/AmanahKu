<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\AuditsChanges;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasAuditedFields;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * CR-34: one row per tenant for the Friday management-meeting task/reminder. A tenant with
 * no row yet (forTenant() below) reads the spec's own defaults, so every command and the
 * overdue panel work correctly before HR ever visits the settings screen.
 */
class ManagementMeetingSettings extends Model implements HasAuditedFields
{
    use AuditsChanges;
    use BelongsToTenant;

    protected $guarded = [];

    public const DEFAULT_MEETING_DAY = 5; // ISO-8601 weekday, Friday

    public const DEFAULT_MEETING_TIME = '17:00';

    public const DEFAULT_REMINDER_TIME = '15:00';

    public const DEFAULT_TASK_TIME = '08:00';

    public const DEFAULT_ATTENDEE_ROLES = ['manager', 'hr', 'management', 'director'];

    protected function casts(): array
    {
        return ['attendee_roles' => 'array', 'paused_until' => 'date'];
    }

    public function audited(): array
    {
        return ['meeting_day', 'meeting_time', 'reminder_time', 'task_time', 'attendee_roles', 'paused_until'];
    }

    /** The current tenant's settings, or an unsaved instance carrying the spec's defaults. */
    public static function forTenant(): self
    {
        return static::query()->first() ?? new self([
            'meeting_day' => self::DEFAULT_MEETING_DAY,
            'meeting_time' => self::DEFAULT_MEETING_TIME,
            'reminder_time' => self::DEFAULT_REMINDER_TIME,
            'task_time' => self::DEFAULT_TASK_TIME,
            'attendee_roles' => self::DEFAULT_ATTENDEE_ROLES,
            'paused_until' => null,
        ]);
    }

    /** @return list<string> */
    public function roles(): array
    {
        return $this->attendee_roles ?: self::DEFAULT_ATTENDEE_ROLES;
    }

    public function isPausedOn(Carbon $day): bool
    {
        return $this->paused_until !== null && $day->toDateString() <= $this->paused_until->toDateString();
    }
}
