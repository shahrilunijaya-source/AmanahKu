<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\TotSession;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * The day-before TOT announcement, in the wording the team already sends by hand.
 *
 * Everybody active gets one. Separate from AppNotificationMail because that one is a
 * generic English copy of a bell; this carries the presenter, the topic, the hour and the
 * Meet link in Malay, which is the format people expect for TOT.
 */
class TotTomorrowMail extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private TotSession $session,
        private string $boardUrl,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        // A slot can reach the day before with nobody linked: an imported nickname in
        // presenter_name, or nobody at all. A team slot reads "A, B & C".
        $team = $this->session->presenterList();
        $presenter = $this->session->presenterLabel() ?: 'PIC yang akan diumumkan';

        // Only a solo presenter's number goes in the line — one phone next to three names
        // reads as though it belongs to the last of them.
        $phone = $team->count() === 1 ? $team->first()->phone : null;
        $topic = $this->session->title ?: 'tajuk yang akan diumumkan';
        $date = $this->session->session_date->locale('ms')->translatedFormat('l, d F Y');
        $start = $this->malayTime($this->session->startTime());
        $end = $this->malayTime($this->session->endTime());
        $meet = $this->meetLink();

        $mail = (new MailMessage)
            ->subject('Sesi TOT esok: '.$topic)
            ->greeting('Assalamualaikum dan salam sejahtera semua.')
            ->line(sprintf(
                'Untuk makluman, sesi Transfer of Technology (TOT) pada hari esok akan disampaikan oleh Saudara %s%s dengan tajuk "%s".',
                $presenter,
                $phone ? ' '.$phone : '',
                $topic,
            ))
            ->line('Sesi akan bermula tepat pada '.$start.'.')
            ->line('🗓️ '.$date)
            ->line('⏰ '.$start.' – '.$end)
            ->line('🌍 Zon Masa: Asia/Kuala_Lumpur');

        if ($meet) {
            $mail->line('🔗 Pautan Google Meet:')
                ->action('Sertai Google Meet', $meet);
        } else {
            $mail->action('Buka papan TOT', $this->boardUrl);
        }

        return $mail->line('Semua dijemput untuk menyertai sesi ini. Terima kasih.');
    }

    /**
     * "10:30 pagi". Carbon's ms locale writes AM/PM as PG/PTG, which is not how the team
     * writes the announcement, so the part of day is spelled out here instead.
     */
    private function malayTime(Carbon $time): string
    {
        $part = match (true) {
            $time->hour < 12 => 'pagi',
            $time->hour === 12 => 'tengah hari',
            $time->hour < 19 => 'petang',
            default => 'malam',
        };

        return $time->format('g:i').' '.$part;
    }

    /**
     * The Meet link out of the slot's links list. Any google meet URL wins; otherwise the
     * first link, because a presenter who records one link records the joining one.
     */
    private function meetLink(): ?string
    {
        $urls = collect($this->session->links ?? [])
            ->pluck('url')
            ->filter(fn (?string $url) => filled($url));

        return $urls->first(fn (string $url) => str_contains($url, 'meet.google.com'))
            ?? $urls->first();
    }
}
