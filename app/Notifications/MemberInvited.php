<?php

namespace App\Notifications;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

/**
 * Emails a freshly-invited member their one-time credentials and a sign-in link.
 * Sent through whatever mailer is configured (log in dev, SMTP in staging/prod),
 * so the credential never has to be relayed by hand.
 */
class MemberInvited extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private Tenant $tenant,
        private string $tempPassword,
        private string $role,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        // Signed, expiring activation link — lets the member set their own password
        // directly, without relaying the one-time password by hand.
        $activationUrl = URL::temporarySignedRoute(
            'activation.show',
            now()->addDays(7),
            ['user' => $notifiable->getKey()],
        );

        return (new MailMessage)
            ->subject("You've been added to {$this->tenant->name} on Amanahku")
            ->greeting("Hi {$notifiable->name},")
            ->line("You've been added to {$this->tenant->name} as ".ucfirst($this->role).'.')
            ->line('Activate your account using the button below and set your own password.')
            ->action('Activate your account', $activationUrl)
            ->line('This activation link expires in 7 days.')
            ->line("Email: {$notifiable->email}")
            ->line("Alternatively, sign in at ".url('/login/'.$this->tenant->slug)." with this one-time password: {$this->tempPassword} (you'll be asked to change it immediately).")
            ->line('For your security, do not share these details.');
    }
}
