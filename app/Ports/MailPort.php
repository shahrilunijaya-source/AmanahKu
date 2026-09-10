<?php

namespace App\Ports;

use App\Ports\Data\MailMessage;

/** Frozen by docs/build/contracts/ports.md. New outbound mail in the run goes through here, never the Mail facade. */
interface MailPort
{
    public function send(MailMessage $message): PortResult;
}
