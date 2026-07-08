<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\PerformanceReview;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    private const REVIEWER_ROLES = ['manager', 'management', 'hr'];

    /** A manager/HR scores an open review (the reviewer side of the loop). */
    public function complete(Request $request, PerformanceReview $review): RedirectResponse
    {
        $this->authorizeReviewer($request, $review);
        abort_unless(in_array($review->status, ['scheduled', 'in_progress'], true), 422, 'Review is not open for scoring.');

        $data = $request->validate([
            'overall_rating' => ['required', 'numeric', 'min:0', 'max:5'],
            'rating_label' => ['required', 'string', 'max:60'],
            'strengths' => ['nullable', 'string', 'max:2000'],
            'improvements' => ['nullable', 'string', 'max:2000'],
            'goals' => ['nullable', 'string', 'max:2000'],
            'c_delivery' => ['required', 'numeric', 'min:0', 'max:5'],
            'c_collaboration' => ['required', 'numeric', 'min:0', 'max:5'],
            'c_leadership' => ['required', 'numeric', 'min:0', 'max:5'],
        ]);

        $review->update([
            'status' => 'completed',
            'reviewer_id' => $request->attributes->get('employee')?->id,
            'overall_rating' => $data['overall_rating'],
            'rating_label' => $data['rating_label'],
            'strengths' => $data['strengths'] ?? null,
            'improvements' => $data['improvements'] ?? null,
            'goals' => $data['goals'] ?? null,
            'competencies' => [
                ['label' => 'Delivery & results', 'score' => (float) $data['c_delivery']],
                ['label' => 'Collaboration', 'score' => (float) $data['c_collaboration']],
                ['label' => 'Leadership', 'score' => (float) $data['c_leadership']],
            ],
            'review_date' => now()->toDateString(),
        ]);

        AuditLog::record('Completed review', $review->employee->name.' · '.$review->cycle);

        return back()->with('ok', 'Review scored for '.$review->employee->name.'.');
    }

    /**
     * A manager/HR enters or updates reviewer competency ratings, an overall score,
     * and reviewer comments on an open review (the reviewer rating-entry workflow).
     * Optionally finalises the review in the same submit.
     */
    public function rate(Request $request, PerformanceReview $review): RedirectResponse
    {
        $this->authorizeReviewer($request, $review);
        abort_unless(
            in_array($review->status, ['scheduled', 'in_progress'], true),
            422,
            'Reviewer ratings can only be entered while the cycle is open.'
        );

        $data = $request->validate([
            'reviewer_overall' => ['required', 'numeric', 'min:0', 'max:5'],
            'rating_label' => ['required', 'string', 'max:60'],
            'reviewer_comments' => ['nullable', 'string', 'max:2000'],
            'r_delivery' => ['required', 'numeric', 'min:0', 'max:5'],
            'r_collaboration' => ['required', 'numeric', 'min:0', 'max:5'],
            'r_leadership' => ['required', 'numeric', 'min:0', 'max:5'],
            'finalize' => ['nullable', 'boolean'],
        ]);

        $scores = [
            ['label' => 'Delivery & results', 'score' => (float) $data['r_delivery']],
            ['label' => 'Collaboration', 'score' => (float) $data['r_collaboration']],
            ['label' => 'Leadership', 'score' => (float) $data['r_leadership']],
        ];

        $attributes = [
            'reviewer_id' => $request->attributes->get('employee')?->id,
            'reviewer_scores' => $scores,
            'reviewer_overall' => (float) $data['reviewer_overall'],
            'reviewer_comments' => $data['reviewer_comments'] ?? null,
            'rating_label' => $data['rating_label'],
            'reviewer_rated_at' => now(),
        ];

        $finalize = (bool) ($data['finalize'] ?? false);

        if ($finalize) {
            // Promote reviewer ratings into the shared display fields and close the cycle.
            $attributes['status'] = 'completed';
            $attributes['overall_rating'] = (float) $data['reviewer_overall'];
            $attributes['competencies'] = $scores;
            $attributes['review_date'] = now()->toDateString();
        }

        $review->update($attributes);

        AuditLog::record(
            $finalize ? 'Completed review' : 'Rated review',
            $review->employee->name.' · '.$review->cycle
        );

        return back()->with(
            'ok',
            ($finalize ? 'Review scored and completed for ' : 'Reviewer ratings saved for ').$review->employee->name.'.'
        );
    }

    /** Employee acknowledges their own completed review. */
    public function acknowledge(Request $request, PerformanceReview $review): RedirectResponse
    {
        $this->authorizeOwnReview($request, $review);
        abort_unless($review->status === 'completed', 422, 'Only a completed review can be acknowledged.');

        $review->update(['status' => 'acknowledged', 'acknowledged_at' => now()]);

        AuditLog::record('Acknowledged review', $review->cycle);

        return back()->with('ok', 'Review acknowledged — '.$review->cycle.'.');
    }

    /** Employee writes/updates their self-assessment on an open review. */
    public function selfAssessment(Request $request, PerformanceReview $review): RedirectResponse
    {
        $this->authorizeOwnReview($request, $review);
        abort_unless(
            in_array($review->status, ['scheduled', 'in_progress'], true),
            422,
            'Self-assessment is only editable while the cycle is open.'
        );

        $data = $request->validate([
            'self_assessment' => ['required', 'string', 'max:2000'],
        ]);

        $review->update(['self_assessment' => $data['self_assessment']]);

        return back()->with('ok', 'Self-assessment saved.');
    }

    /**
     * Guard: the acting user must hold a reviewer role and the review must live in
     * the active tenant. Route-model binding resolves before the tenant scope is
     * active, so assert tenant_id here. Mirrors the gating used by complete().
     */
    private function authorizeReviewer(Request $request, PerformanceReview $review): void
    {
        $this->authorizeTenantRole($request, self::REVIEWER_ROLES);
        // A reviewer (HR/management/manager) scores OTHERS — they need not hold a personal
        // employee profile in this workspace. reviewer_id is recorded null-safe when absent.
        abort_unless($review->tenant_id === app(CurrentTenant::class)->id(), 403);
    }

    /**
     * Guard: the acting user must own this review and it must live in the active tenant.
     * Route-model binding resolves before the tenant scope is active, so assert tenant_id here.
     */
    private function authorizeOwnReview(Request $request, PerformanceReview $review): void
    {
        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403, 'No employee profile in this workspace.');
        abort_unless($review->tenant_id === app(CurrentTenant::class)->id(), 403);
        abort_unless($review->employee_id === $employee->id, 403);
    }
}
