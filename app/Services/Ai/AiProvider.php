<?php

namespace App\Services\Ai;

/**
 * Workforce assistant provider. Implementations answer a user's question using the
 * tenant-scoped workforce facts passed in $context (never inventing employee data).
 */
interface AiProvider
{
    /** @param array<string,mixed> $context tenant-scoped facts assembled by the caller */
    public function reply(string $message, array $context): string;

    /** Short label shown as the answer's source (e.g. "Live AI · Claude"). */
    public function label(): string;
}
