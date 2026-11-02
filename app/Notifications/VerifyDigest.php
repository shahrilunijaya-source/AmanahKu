<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;

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
        $screens = array_map(fn (string $kind): string => $kind === 'Leave' ? 'leave' : 'claims', $kinds);

        if (count($screens) === 1) {
            return $mail->action('Open '.$screens[0], route('app.screen', $screens[0]));
        }

        // MailMessage allows one action, so with both kinds pending the two buttons are drawn
        // with the mail theme's own button classes and look the same as the action button.
        $buttons = collect($screens)->map(fn (string $screen): string => '<a href="'.e(route('app.screen', $screen))
            .'" class="button button-primary" target="_blank" rel="noopener" style="margin: 0 6px;">Open '.$screen.'</a>')->implode('');

        return $mail->line(new HtmlString('<div style="text-align: center; margin: 24px 0;">'.$buttons.'</div>'));
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
