<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emails a copy of an in-app bell so a member who is not in the app still sees
 * clock-in nudges, task assignments and approval steps. Queued, so the request
 * that raised the bell never waits on SMTP.
 */
class AppNotificationMail extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private string $title,
        private ?string $body = null,
        private ?string $url = null,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->title)
            ->greeting("Hi {$notifiable->name},")
            ->line($this->body ?? $this->title);

        return $this->url ? $mail->action('Open Amanahku', $this->url) : $mail;
    }
}
