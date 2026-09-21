<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\PayrollSubmission;
use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * Spec F12: the reminder a tenant's HR/management users get before a statutory deadline,
 * listing only the filings that are still not submitted. Built by payroll:deadline-digest,
 * which does the querying — this class only renders.
 */
class PayrollDeadlineDigest extends Notification implements ShouldQueue
{
    use Queueable;

    /** @param  Collection<int, PayrollSubmission>  $rows */
    public function __construct(private Tenant $tenant, private Collection $rows) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $due = $this->rows->first()?->due_on;

        $mail = (new MailMessage)
            ->subject('Statutory filings due '.($due !== null ? $due->format('j F Y') : 'soon').' — '.$this->tenant->name)
            ->greeting('Hi '.$notifiable->name.',')
            ->line('These filings for '.$this->tenant->name.' are not marked submitted yet.');

        foreach ($this->rows as $row) {
            $label = PayrollSubmission::LABELS[$row->agency][0] ?? $row->agency;
            $for = $row->payrollRun !== null ? $row->payrollRun->label : (string) $row->year;
            $mail->line('• '.$label.' — '.$for.' — due '.($row->due_on !== null ? $row->due_on->format('j M Y') : ''));
        }

        return $mail->action('Open payroll deadlines', route('app.screen', ['screen' => 'payroll-payment', 'tab' => 'deadlines']))
            ->line('Record the receipt on the Deadlines tab once each one is filed.');
    }
}
