<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\ProbationCheckin;
use App\Models\ProbationReview;
use App\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class ProbationSeeder extends Seeder
{
    /**
     * Seed probation reviews for the Unijaya tenant: one ACTIVE review for the
     * employee currently on probation (Farah Aziz, 90 days from her 2026-06-11
     * onboarding) with a completed 30-day check-in, plus one already-confirmed
     * review for history. Safe to re-run: skips if the tenant already has reviews,
     * and bails if the tenant or employees are missing. The global tenant scope is
     * inactive in seeders (no session), so tenant_id is set explicitly.
     */
    public function run(): void
    {
        $tenant = Tenant::where('slug', 'unijaya')->first();
        if (! $tenant) {
            return;
        }

        $tid = $tenant->id;

        if (ProbationReview::where('tenant_id', $tid)->exists()) {
            return;
        }

        $hr = Employee::where('tenant_id', $tid)->where('name', 'Aisyah Rahman')->first();

        // ── Active probation for Farah Aziz (currently status 'probation'). ──
        $farah = Employee::where('tenant_id', $tid)->where('name', 'Farah Aziz')->first();
        if ($farah) {
            $start = Carbon::create(2026, 6, 11);
            $review = ProbationReview::create([
                'tenant_id' => $tid,
                'employee_id' => $farah->id,
                'start_date' => $start->toDateString(),
                'end_date' => $start->copy()->addDays(90)->toDateString(),
                'length_days' => 90,
                'status' => 'active',
            ]);

            ProbationCheckin::create([
                'tenant_id' => $tid,
                'probation_review_id' => $review->id,
                'milestone' => '30-day',
                'note' => 'Settling in well. Strong grasp of the brand guidelines and eager to take on campaign work. Continue pairing on analytics reporting.',
                'rating' => 4,
                'checkin_date' => $start->copy()->addDays(30)->toDateString(),
            ]);

            ProbationCheckin::create([
                'tenant_id' => $tid,
                'probation_review_id' => $review->id,
                'milestone' => '60-day',
                'note' => 'On track. Delivered first solo social campaign. Working on time-management under deadline pressure.',
                'rating' => 4,
                'checkin_date' => $start->copy()->addDays(60)->toDateString(),
            ]);
        }

        // ── Confirmed probation (history) for another employee. ──
        $other = Employee::where('tenant_id', $tid)
            ->whereNotIn('name', ['Farah Aziz'])
            ->orderBy('id')
            ->first();

        if ($other) {
            $hstart = Carbon::create(2025, 9, 1);
            $confirmed = ProbationReview::create([
                'tenant_id' => $tid,
                'employee_id' => $other->id,
                'start_date' => $hstart->toDateString(),
                'end_date' => $hstart->copy()->addDays(90)->toDateString(),
                'length_days' => 90,
                'status' => 'confirmed',
                'decision_note' => 'Met all probation objectives. Confirmed to permanent staff.',
                'decided_at' => $hstart->copy()->addDays(90),
                'decided_by_id' => $hr?->id,
            ]);

            ProbationCheckin::create([
                'tenant_id' => $tid,
                'probation_review_id' => $confirmed->id,
                'milestone' => '90-day',
                'note' => 'Consistently exceeded expectations across the review period. Recommended for confirmation.',
                'rating' => 5,
                'checkin_date' => $hstart->copy()->addDays(88)->toDateString(),
            ]);
        }
    }
}
