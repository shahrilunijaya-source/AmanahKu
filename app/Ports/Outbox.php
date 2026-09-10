<?php

namespace App\Ports;

use App\Models\PortOutbox;
use App\Tenancy\CurrentTenant;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The one path every adapter takes: write the outbox row, run the adapter body, mark the
 * row sent or failed. The body returns `[externalId, payload]`; anything it throws
 * becomes `ok = false` with the message on the row, never an exception for the caller.
 */
final class Outbox
{
    public function __construct(private CurrentTenant $tenant) {}

    /**
     * @param  array<string, mixed>  $payload  the call's plain arguments, kept on the row
     * @param  Closure(PortOutbox): array{0: ?string, 1: array<mixed>}  $run
     */
    public function call(string $port, string $method, ?Model $subject, array $payload, Closure $run, ?int $tenantId = null): PortResult
    {
        try {
            $row = PortOutbox::create([
                'tenant_id' => $tenantId ?? $this->tenant->id() ?? $subject?->getAttribute('tenant_id'),
                'port' => $port,
                'method' => $method,
                'subject_type' => $subject?->getMorphClass(),
                'subject_id' => $subject?->getKey(),
                'payload' => $payload,
                'status' => PortOutbox::STATUS_PENDING,
            ]);
        } catch (Throwable $e) {
            report($e);
            Log::warning("Port {$port}.{$method} could not write its outbox row: {$e->getMessage()}");

            return new PortResult(ok: false, externalId: null, payload: [], outboxId: 0);
        }

        $row->attempts++;

        try {
            [$externalId, $result] = $run($row);
            $row->forceFill(['status' => PortOutbox::STATUS_SENT, 'external_id' => $externalId, 'sent_at' => now(), 'error' => null])->save();

            return new PortResult(ok: true, externalId: $externalId, payload: $result, outboxId: $row->id);
        } catch (Throwable $e) {
            $row->forceFill(['status' => PortOutbox::STATUS_FAILED, 'error' => mb_substr($e->getMessage(), 0, 2000)])->save();

            return new PortResult(ok: false, externalId: null, payload: [], outboxId: $row->id);
        }
    }
}
