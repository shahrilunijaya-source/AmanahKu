<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\GoogleCalendarConnection;
use App\Models\Tenant;
use App\Models\WorkItem;
use App\Services\GoogleCalendarClient;
use App\Tenancy\CurrentTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Create/update/delete a work item's Google Calendar event. Takes scalars, not
 * an Eloquent WorkItem instance: on delete-after-reassignment or delete-after-
 * destroy the model may already be gone or already carry the NEW assignee by
 * the time this runs, so the caller (WorkItemObserver) captures whatever it
 * needs at dispatch time instead of relying on a serialized model.
 *
 * Tenant-aware like the queued digest commands: WorkItem/GoogleCalendarConnection
 * queries need CurrentTenant set explicitly because a queued job runs outside the
 * request lifecycle that normally resolves it.
 */
class SyncWorkItemCalendarEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $tenantId,
        public readonly string $action,
        public readonly ?int $workItemId = null,
        public readonly ?int $userId = null,
        public readonly ?string $googleEventId = null,
    ) {}

    public function handle(CurrentTenant $context, GoogleCalendarClient $client): void
    {
        $tenant = Tenant::find($this->tenantId);
        if (! $tenant) {
            return;
        }

        // Restore whatever was active before, not null — on the `sync` queue driver
        // (used in tests, and possibly locally) this job runs INLINE inside the
        // request that dispatched it, so blindly nulling context here would wipe
        // the request's own tenant scope out from under it after this job returns.
        $previous = $context->get();
        $context->set($tenant);

        try {
            $this->action === 'delete' ? $this->runDelete($client) : $this->runUpsert($client);
        } finally {
            $context->set($previous);
        }
    }

    private function runUpsert(GoogleCalendarClient $client): void
    {
        $item = WorkItem::find($this->workItemId);
        if (! $item || ! $item->due_at || $item->archived_at !== null || $item->status === 'done') {
            return;
        }

        $userId = $item->employee?->user_id;
        if (! $userId) {
            return;
        }

        $connection = GoogleCalendarConnection::where('user_id', $userId)->first();
        if (! $connection) {
            return;
        }

        $eventId = $client->createOrUpdateEvent($item, $connection);
        $item->update(['google_event_id' => $eventId]);
    }

    private function runDelete(GoogleCalendarClient $client): void
    {
        if (! $this->userId || ! $this->googleEventId) {
            return;
        }

        $connection = GoogleCalendarConnection::where('user_id', $this->userId)->first();
        if ($connection) {
            $client->deleteEvent($this->googleEventId, $connection);
        }

        if ($this->workItemId) {
            // Scoped by the event id we just deleted, not just the item id — if a
            // later upsert already wrote a newer event id onto this row, don't clobber it.
            WorkItem::where('id', $this->workItemId)
                ->where('google_event_id', $this->googleEventId)
                ->update(['google_event_id' => null]);
        }
    }
}
