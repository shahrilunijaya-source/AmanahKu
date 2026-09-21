<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Payslip;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Spec F13: the email a staff member gets when the run holding their payslip is
 * published. Deliberately carries no figures beyond the net pay the in-app notice
 * already shows — the payslip itself stays behind the login.
 */
class PayslipPublished extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private Payslip $payslip) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $run = $this->payslip->payrollRun;
        $label = $run !== null ? $run->label : 'payroll';

        return (new MailMessage)
            ->subject('Your '.$label.' payslip is ready')
            ->greeting('Hi '.$notifiable->name.',')
            ->line('Your payslip for '.$label.' has been issued.')
            ->line('Net pay: RM '.number_format((float) $this->payslip->net_pay, 2))
            ->action('View payslip', route('app.screen', ['screen' => 'payroll-my', 'payslip' => $this->payslip->id]))
            ->line('Payment date: '.($run?->payment_date?->format('j F Y') ?? 'to be confirmed').'.');
    }
}
