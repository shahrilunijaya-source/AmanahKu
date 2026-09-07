<?php

namespace App\Ports\Stub;

use App\Ports\Data\MailMessage;
use App\Ports\MailPort;
use App\Ports\Outbox;
use App\Ports\PortResult;
use RuntimeException;

/** Records the mail in port_outbox and sends nothing, not even to Mailpit. The only mail driver enabled during the run. */
final class StubMailPort implements MailPort
{
    public function __construct(private Outbox $outbox) {}

    public function send(MailMessage $message): PortResult
    {
        return $this->outbox->call('mail', 'send', $message->about, $message->toPayload(), function ($row) use ($message) {
            if ($message->to === []) {
                throw new RuntimeException('No recipient: the message names nobody to send to.');
            }

            return ["stub-mail-{$row->id}", []];
        });
    }
}
