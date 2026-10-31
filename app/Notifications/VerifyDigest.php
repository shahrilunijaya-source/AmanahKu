<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * One email listing every leave and claim waiting for this manager's verification.
 * Built by approvals:verify-digest; the items are pre-computed so this only renders.
 */
class VerifyDigest extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<array{kind: string, who: string, what: string, since: \DateTimeInterface}>  $items  oldest first
     */
    public function __construct(private array $items) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $count = count($this->items);

        $mail = (new MailMessage)
            ->subject($count.' request'.($count === 1 ? '' : 's').' awaiting your verification')
            ->greeting("Hi {$notifiable->name},")
            ->line('These are still waiting for you to verify:');

        foreach ($this->items as $item) {
            $mail->line("• **{$item['kind']}**, {$item['who']}: {$item['what']}, waiting since ".$item['since']->format('j M'));
        }

        $kinds = array_unique(array_column($this->items, 'kind'));
        $screen = ['Leave' => 'leave', 'Claim' => 'claims'];

        $mail->action('Open '.$screen[$kinds[0]], route('app.screen', $screen[$kinds[0]]));

        // Both kinds pending: leave is listed first, so the button opens leave and a plain link covers claims.
        if (count($kinds) > 1) {
            $mail->line('Claims: '.route('app.screen', 'claims'));
        }

        return $mail;
    }

    /**
     * The listed items, for tests.
     *
     * @return list<array{kind: string, who: string, what: string, since: \DateTimeInterface}>
     */
    public function items(): array
    {
        return $this->items;
    }
}
