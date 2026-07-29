<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Plain-language "what this decision means" copy for a dashboard queue row. The row's
 * title/sub already say WHAT the request is; this says what happens if you act on it —
 * the thing people actually need to know before they click Verify or Approve.
 *
 * `type` is the request kind ('leave', 'claim', 'overtime'); `stage` is where it sits
 * ('verify', 'approve', 'stuck', 'waiting', 'done', 'step1', 'step2').
 */
class RequestGuidance
{
    public static function for(string $type, string $stage): string
    {
        $label = match ($type) {
            'leave' => 'leave',
            'claim' => 'claim',
            'overtime' => 'overtime request',
            default => 'request',
        };

        return match ($stage) {
            'verify' => match ($type) {
                'leave' => 'Verifying passes it to management for final approval — it is not the final approval, and it does not decrement the balance yet.',
                'claim' => 'Verifying passes it to management for final approval — it is not the final approval, and nothing is queued for payment yet.',
                default => "Verifying passes this {$label} to management for final approval — it is not the final approval yet.",
            },
            'approve' => match ($type) {
                'claim' => 'Already verified by their manager, so this one is yours to approve. Approving queues it for the next payroll run; nothing pays out until that run is finalised.',
                'leave' => 'Already verified by their manager, so this one is yours to approve. Approving decrements their leave balance and confirms the time off.',
                default => "Already verified by their manager, so this {$label} is yours to approve. Approving finalises it.",
            },
            'stuck' => 'They have no superior on the org chart, so the request was never routed to a verify queue and never will be. Assign a superior and it moves the same day.',
            'waiting' => 'Waiting on someone else — there is nothing for you to do here yet.',
            'step1' => 'With your manager for the first check. They verify it, then it moves to management for final approval.',
            'step2' => 'Verified by your manager and now with management for final approval. This is the last step.',
            'done' => 'Approved — nothing left to do.',
            default => 'No action needed right now.',
        };
    }
}
