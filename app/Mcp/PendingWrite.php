<?php

declare(strict_types=1);

namespace App\Mcp;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The two-step confirm flow every MCP write tool uses: a preview tool validates
 * and authorizes a change, then stashes the fully-validated payload here instead
 * of applying it. ConfirmWriteTool later consumes the token and does the actual
 * write, re-checking authorization at that point (state can change inside the TTL).
 *
 * The payload is stored as-is — not hashed and re-sent by the client — because the
 * whole point is that the model never gets to construct the write itself a second
 * time; it can only replay exactly what was already validated and shown to the user.
 *
 * Backed by the app's cache store (database in every environment that matters here
 * — staging confirmed working), keyed by a random, unguessable token so a leaked
 * jsonrpc id or tool name can never be turned into a token guess.
 */
final class PendingWrite
{
    private const TTL_MINUTES = 10;

    private const PREFIX = 'mcp:pending-write:';

    /**
     * Stash a validated write for later confirmation. Returns the token the caller
     * must hand back to ConfirmWriteTool within the TTL.
     */
    public function stash(array $payload, string $tool, int $userId, int $tenantId): string
    {
        $token = Str::random(40);

        Cache::put(self::PREFIX.$token, [
            'payload' => $payload,
            'tool' => $tool,
            'user_id' => $userId,
            'tenant_id' => $tenantId,
        ], now()->addMinutes(self::TTL_MINUTES));

        return $token;
    }

    /**
     * Redeem a token once. Refuses (returns null) if the token is missing/expired,
     * or if it was stashed for a different user or tenant — a stolen token is
     * useless outside the session that created it. Single use: the entry is
     * removed whether or not the caller matches, so a wrong guess can't be retried
     * against the same token.
     *
     * @return array{payload: array, tool: string, user_id: int, tenant_id: int}|null
     */
    public function consume(string $token, int $userId, int $tenantId): ?array
    {
        /** @var array{payload: array, tool: string, user_id: int, tenant_id: int}|null $entry */
        $entry = Cache::pull(self::PREFIX.$token);

        if ($entry === null) {
            return null;
        }

        if ($entry['user_id'] !== $userId || $entry['tenant_id'] !== $tenantId) {
            return null;
        }

        return $entry;
    }
}
