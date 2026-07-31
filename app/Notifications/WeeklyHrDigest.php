<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Weekly HR digest emailed to a tenant's management/HR users.
 *
 * Carries an immutable, pre-computed per-tenant summary (built by the
 * digest:weekly command) so the notification itself does no querying — it
 * just renders. Queued so the scheduled run returns immediately and the
 * mail is delivered by the queue worker.
 *
 * The $summary shape (all ints unless noted):
 *   pending: ['leave','claims','expenses','overtime'] — items awaiting a decision
 *   newJoiners            — employees who joined in the last 7 days
 *   probationDecisions    — active probation reviews ending in the next 30 days
 *   complianceExpiries    — licences/certs expiring in the next 30 days
 */
class WeeklyHrDigest extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array{pending: array{leave:int, claims:int, expenses:int, overtime:int}, newJoiners:int, probationDecisions:int, complianceExpiries:int}  $summary
     */
    public function __construct(
        private Tenant $tenant,
        private array $summary,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $pending = $this->summary['pending'];
        $totalPending = $pending['leave'] + $pending['claims'] + $pending['expenses'] + $pending['overtime'];

        $mail = (new MailMessage)
            ->subject("Weekly HR digest — {$this->tenant->name}")
            ->greeting("Hi {$notifiable->name},")
            ->line("Here's this week's HR summary for {$this->tenant->name}.")
            ->line("**Pending approvals: {$totalPending}**")
            ->line("• Leave: {$pending['leave']}")
            ->line("• Claims: {$pending['claims']}")
            ->line("• Expenses: {$pending['expenses']}")
            ->line("• Overtime: {$pending['overtime']}")
            ->line("**New joiners (last 7 days): {$this->summary['newJoiners']}**")
            ->line("**Probation decisions due (next 30 days): {$this->summary['probationDecisions']}**")
            ->line("**Compliance/licence expiries (next 30 days): {$this->summary['complianceExpiries']}**")
            ->action('Open Amanahku', url('/app/dashboard'))
            ->line('You are receiving this because you hold an HR or management role.');

        return $mail;
    }

    /**
     * Exposed for assertions/tests — the summary this digest renders.
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return $this->summary;
    }
}
