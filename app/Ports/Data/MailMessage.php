<?php

namespace App\Ports\Data;

use Illuminate\Database\Eloquent\Model;

/**
 * One outbound mail: recipients, subject, both languages of the body, and a `kind` naming
 * the feature that sent it. `about` is the app row the mail concerns, for the outbox.
 */
final readonly class MailMessage
{
    /** @param list<string> $to */
    public function __construct(
        public array $to,
        public string $subject,
        public string $bodyEn,
        public string $bodyMs,
        public string $kind,
        public ?Model $about = null,
    ) {}

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return ['to' => $this->to, 'subject' => $this->subject, 'body_en' => $this->bodyEn, 'body_ms' => $this->bodyMs, 'kind' => $this->kind];
    }
}
