<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Employee;
use App\Models\ManagementMeetingSettings;
use App\Models\Project;
use App\Models\PublicHoliday;
use App\Tenancy\CurrentTenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * CR-34: shared maths and recipient lookup for the two Friday management-meeting commands
 * (`management:meeting-tasks`, `management:meeting-reminder`) and for the CR-17 overdue
 * panel's 5 PM same-day rule. One place so the task, the email and the "who counts as a
 * manager" set can never drift apart.
 */
class ManagementMeeting
{
    public const PROJECT_NAME = 'URSB : Management meeting';

    public const PROJECT_CODE = 'URSB-MM';

    public function settings(): ManagementMeetingSettings
    {
        return ManagementMeetingSettings::forTenant();
    }

    /**
     * The meeting date for the week containing $today: the tenant's configured weekday,
     * walked back one working day at a time while it lands on a public holiday (never for
     * a plain weekend — only a holiday moves the meeting, per the spec's own wording).
     */
    public function meetingDateForWeek(Carbon $today, ManagementMeetingSettings $settings): Carbon
    {
        $nominal = $today->copy()->startOfWeek(Carbon::MONDAY)->addDays($settings->meeting_day - 1);

        while ($this->isPublicHoliday($nominal)) {
            $nominal = $nominal->copy()->subDay();
        }

        return $nominal;
    }

    /** Whether $today is the day either scheduled command should act for this tenant. */
    public function isTriggerDay(Carbon $today, ManagementMeetingSettings $settings): bool
    {
        return $this->meetingDateForWeek($today, $settings)->isSameDay($today);
    }

    private function isPublicHoliday(Carbon $day): bool
    {
        return PublicHoliday::whereDate('date', $day->toDateString())->exists();
    }

    /**
     * Every active, non-archived employee who is PM/PE on a live project, plus every active
     * employee whose tenant-membership role is in the tenant's attendee roles. One row per
     * person, however many projects or roles qualify them.
     *
     * @return Collection<int, Employee>
     */
    public function recipients(ManagementMeetingSettings $settings): Collection
    {
        $tenant = app(CurrentTenant::class)->get();

        $projectManagerIds = Project::query()
            ->where('is_active', true)->whereNull('closed_at')
            ->get(['pm_id', 'pe_id'])
            ->flatMap(fn (Project $p) => [$p->pm_id, $p->pe_id])
            ->filter()
            ->unique();

        $roleUserIds = $tenant
            ? $tenant->users()->wherePivotIn('role', $settings->roles())->pluck('users.id')
            : collect();

        return Employee::query()->active()
            ->where(fn ($q) => $q->whereIn('id', $projectManagerIds)->orWhereIn('user_id', $roleUserIds))
            ->orderBy('id')
            ->get();
    }

    /** The tenant's 'URSB : Management meeting' project, created the first time it's needed. */
    public function project(): Project
    {
        $tenantId = app(CurrentTenant::class)->id();

        return Project::query()->firstOrCreate(
            ['tenant_id' => $tenantId, 'name' => self::PROJECT_NAME],
            ['code' => self::PROJECT_CODE],
        );
    }
}
