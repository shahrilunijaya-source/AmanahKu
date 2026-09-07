<?php

namespace App\Ports\Data;

/** A project as Track reports it. The smallest set CR-06c will need; the stub returns none. */
final readonly class TrackProject
{
    public function __construct(
        public string $trackRef,
        public string $name,
        public ?string $status = null,
        public ?string $clientName = null,
    ) {}

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return ['track_ref' => $this->trackRef, 'name' => $this->name, 'status' => $this->status, 'client_name' => $this->clientName];
    }
}
