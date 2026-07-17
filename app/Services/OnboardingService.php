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
     * The standard onboarding checklist seeded with each new profile: [track, key, title].
     * track is constrained to the onboarding_tasks.track enum (general | position). key is a
     * stable slug linking the task to its content-library entry (onboarding_resources.item_key)
     * — general items share one company-wide entry; position items may add per-position overrides.
     *
     * @var list<array{0:string,1:string,2:string}>
     */
    public const STANDARD_CHECKLIST = [
        ['general', 'company-intro', 'Company introduction & history'],
        ['general', 'vision-values', 'Vision, mission & values'],
        ['general', 'handbook', 'Employee handbook acknowledgement'],
        ['general', 'it-security', 'IT security & acceptable use policy'],
        ['general', 'submit-documents', 'Submit required documents'],
        ['general', 'policy-acceptance', 'Digital acceptance of policies'],
        ['position', 'job-description', 'Review job description & standard tasks'],
        ['position', 'systems-access', 'Access to systems & tools'],
        ['position', 'meet-mentor', 'Meet assigned mentor'],
        ['position', 'plan-30', '30-day plan agreed with manager'],
        ['position', 'plan-60', '60-day plan'],
        ['position', 'plan-90', '90-day plan & confirmation checklist'],
    ];

    /**
     * The standard items keyed by slug for the content editor and hire-side resolution:
     * item_key => ['track' => …, 'title' => …]. Ordering follows STANDARD_CHECKLIST.
     *
     * @return array<string, array{track:string, title:string}>
     */
    public static function standardItems(): array
    {
        $items = [];
        foreach (self::STANDARD_CHECKLIST as [$track, $key, $title]) {
            $items[$key] = ['track' => $track, 'title' => $title];
        }

        return $items;
    }

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

        foreach (self::STANDARD_CHECKLIST as $i => [$track, $key, $title]) {
            $profile->tasks()->create([
                'track' => $track,
                'item_key' => $key,
                'title' => $title,
                'done' => false,
                'sort' => $i,
            ]);
        }

        AuditLog::record('Started onboarding', $employee->name);

        return $profile;
    }
}
