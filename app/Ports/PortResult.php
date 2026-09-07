<?php

namespace App\Ports;

/**
 * What every port call hands back (docs/build/contracts/ports.md). Never an exception:
 * a failure is `ok = false` with the error on the outbox row named by `outboxId`.
 */
final readonly class PortResult
{
    /** @param array<mixed> $payload what the adapter returned: a list of events or projects, or nothing */
    public function __construct(
        public bool $ok,
        public ?string $externalId,
        public array $payload,
        public int $outboxId,
    ) {}
}
