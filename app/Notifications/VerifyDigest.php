<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * One email listing every leave and claim waiting on this person: the ones they must
 * verify (as a manager) and the ones they must approve (as HR or a director). Built by
 * approvals:verify-digest; the items are pre-computed so this only renders.
 */
class VerifyDigest extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<array{kind: string, who: string, what: string, since: \DateTimeInterface}>  $toVerify  oldest first
     * @param  list<array{kind: string, who: string, what: string, since: \DateTimeInterface}>  $toApprove  oldest first
     */
    public function __construct(private array $toVerify, private array $toApprove = []) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $count = count($this->toVerify) + count($this->toApprove);
        $step = match (true) {
            $this->toApprove === [] => 'verification',
            $this->toVerify === [] => 'approval',
            default => 'action',
        };

        $mail = (new MailMessage)
            ->subject($count.' request'.($count === 1 ? '' : 's').' awaiting your '.$step)
            ->greeting("Hi {$notifiable->name},");

        foreach (['These are still waiting for you to verify:' => $this->toVerify, 'These are verified and waiting for your final approval:' => $this->toApprove] as $heading => $items) {
            if ($items === []) {
                continue;
            }
            $mail->line("**{$heading}**");
            foreach ($items as $item) {
                $mail->line("• **{$item['kind']}**, {$item['who']}: {$item['what']}, waiting since ".$item['since']->format('j M'));
            }
        }

        $kinds = array_values(array_unique(array_column([...$this->toVerify, ...$this->toApprove], 'kind')));
        $first = in_array('Leave', $kinds, true) ? 'leave' : 'claims';

        $mail->action('Open '.$first, route('app.screen', $first));

        // Both kinds pending: the button opens leave and a plain link covers claims.
        if (count($kinds) > 1) {
            $mail->line('Claims: '.route('app.screen', 'claims'));
        }

        return $mail;
    }

    /**
     * The listed items, for tests.
     *
     * @return array{verify: list<array{kind: string, who: string, what: string, since: \DateTimeInterface}>, approve: list<array{kind: string, who: string, what: string, since: \DateTimeInterface}>}
     */
    public function items(): array
    {
        return ['verify' => $this->toVerify, 'approve' => $this->toApprove];
    }
}
