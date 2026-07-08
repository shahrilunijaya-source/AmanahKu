<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\OnboardingProfile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Opens onboarding profiles and seeds their standard checklist — the onboarding mirror of
 * OffboardingService. One employee has at most one profile; calling again returns the
 * existing one rather than duplicating. Caller owns authorization; the BelongsToTenant
 * trait fills tenant_id from the active CurrentTenant on create.
 */
class OnboardingService
{
    /**
     * The standard onboarding checklist seeded with each new profile: [track, title].
     * track is constrained to the onboarding_tasks.track enum (general | position).
     *
     * @var list<array{0:string,1:string}>
     */
    public const STANDARD_CHECKLIST = [
        ['general', 'Company introduction & history'],
        ['general', 'Vision, mission & values'],
        ['general', 'Employee handbook acknowledgement'],
        ['general', 'IT security & acceptable use policy'],
        ['general', 'Submit required documents'],
        ['general', 'Digital acceptance of policies'],
        ['position', 'Review job description & standard tasks'],
        ['position', 'Access to systems & tools'],
        ['position', 'Meet assigned mentor'],
        ['position', '30-day plan agreed with manager'],
        ['position', '60-day plan'],
        ['position', '90-day plan & confirmation checklist'],
    ];

    /**
     * Open (or reuse) the employee's onboarding profile. Idempotent: an existing profile
     * for the employee is returned untouched rather than duplicated, so re-submitting the
     * "start onboarding" form never wipes progress.
     */
    public function startOnboarding(
        Employee $employee,
        Carbon|string $startDate,
        ?int $mentorId = null,
        ?int $managerId = null,
        int $totalDays = 90,
    ): OnboardingProfile {
        // Serialize concurrent "start onboarding" for the SAME employee. There is no unique
        // index on onboarding_profiles.employee_id, so without this a double-submit could pass
        // the existence check twice and create two profiles (each with a full duplicate
        // checklist). Locking the always-present employee row makes the check-then-create
        // atomic without a schema change.
        return DB::transaction(function () use ($employee, $startDate, $mentorId, $managerId, $totalDays) {
            Employee::whereKey($employee->id)->lockForUpdate()->first();

            $existing = OnboardingProfile::where('employee_id', $employee->id)->first();
            if ($existing) {
                return $existing;
            }

            return $this->createProfile($employee, $startDate, $mentorId, $managerId, $totalDays);
        });
    }

    /** Create the profile and seed its standard checklist. Assumes the caller has ruled out duplicates. */
    private function createProfile(
        Employee $employee,
        Carbon|string $startDate,
        ?int $mentorId,
        ?int $managerId,
        int $totalDays,
    ): OnboardingProfile {
        $start = $startDate instanceof Carbon ? $startDate : Carbon::parse($startDate);
        // Day counter reflects elapsed time at creation (clamped to the window). Like the
        // seed data it is a snapshot, not a live ticker — the screen shows "Day X of Y".
        $dayNumber = max(0, min($totalDays, (int) $start->copy()->startOfDay()->diffInDays(now()->startOfDay(), false)));

        $profile = OnboardingProfile::create([
            'employee_id' => $employee->id,
            'mentor_id' => $mentorId,
            'manager_id' => $managerId,
            'start_date' => $start->toDateString(),
            'day_number' => $dayNumber,
            'total_days' => $totalDays,
        ]);

        foreach (self::STANDARD_CHECKLIST as $i => [$track, $title]) {
            $profile->tasks()->create([
                'track' => $track,
                'title' => $title,
                'done' => false,
                'sort' => $i,
            ]);
        }

        AuditLog::record('Started onboarding', $employee->name);

        return $profile;
    }
}
