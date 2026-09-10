<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Employee;
use App\Models\GoogleCalendarConnection;
use App\Models\Tenant;
use App\Models\WorkItem;
use App\Ports\CalendarPort;
use App\Support\Calendar\CalendarMirror;
use App\Tenancy\CurrentTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

/**
 * Create/update/delete a work item's calendar event through CalendarPort (CR-01).
 * Takes scalars, not an Eloquent WorkItem instance: on delete-after-reassignment or
 * delete-after-destroy the model may already be gone or already carry the NEW assignee
 * by the time this runs, so WorkItemObserver captures what it needs at dispatch time.
 *
 * Unique until processing: five quick title edits collapse into one push, so the
 * calendar sees exactly one event and no ping-pong (acceptance 5). Five tries with
 * backoff (rule 9); after the last the card records the error for the Sync issues list.
 *
 * Tenant-aware like the queued digest commands: a queued job runs outside the request
 * lifecycle that normally resolves CurrentTenant.
 */
class SyncWorkItemCalendarEventJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** @var list<int> seconds between retries: 1 min, 5 min, 15 min, 1 h */
    public array $backoff = [60, 300, 900, 3600];

    public function __construct(
        public readonly int $tenantId,
        public readonly string $action,
        public readonly ?int $workItemId = null,
        public readonly ?int $userId = null,
        public readonly ?string $googleEventId = null,
    ) {}

    public function uniqueId(): string
    {
        return "{$this->action}:{$this->workItemId}:{$this->userId}:{$this->googleEventId}";
    }

    public function handle(CurrentTenant $context, CalendarPort $port): void
    {
        $tenant = Tenant::find($this->tenantId);
        if (! $tenant) {
            return;
        }

        // Restore whatever was active before, not null: on the `sync` queue driver this
        // job runs inline inside the request that dispatched it.
        $previous = $context->get();
        $context->set($tenant);

        try {
            $this->action === 'delete' ? $this->runDelete($port) : $this->runUpsert($port);
        } finally {
            $context->set($previous);
        }
    }

    public function failed(?Throwable $e): void
    {
        if ($this->workItemId) {
            WorkItem::withoutGlobalScopes()->where('id', $this->workItemId)
                ->update(['calendar_sync_error' => mb_substr($e?->getMessage() ?? 'Calendar sync failed', 0, 500)]);
        }
    }

    private function runUpsert(CalendarPort $port): void
    {
        $item = WorkItem::withoutGlobalScopes()->find($this->workItemId);
        if (! $item || ! CalendarMirror::syncable($item)) {
            return;
        }

        $employee = Employee::withoutGlobalScope('tenant')->find($item->employee_id);
        if (! $employee?->user_id || ! $this->connected($employee)) {
            return;
        }

        $result = $port->upsertEvent($employee, CalendarMirror::event($item));
        if (! $result->ok) {
            throw new RuntimeException("Calendar push failed (outbox #{$result->outboxId}).");
        }

        // Query builder on purpose: a version stamp is not a card edit, no audit row, no observer.
        WorkItem::withoutGlobalScopes()->where('id', $item->id)->update([
            'google_event_id' => $result->externalId,
            'calendar_version' => $result->payload['version'] ?? null,
            'calendar_sync_error' => null,
        ]);
    }

    private function runDelete(CalendarPort $port): void
    {
        if (! $this->userId || ! $this->googleEventId) {
            return;
        }

        $employee = Employee::withoutGlobalScope('tenant')->where('user_id', $this->userId)->where('tenant_id', $this->tenantId)->first();
        if ($employee && $this->connected($employee)) {
            $result = $port->deleteEvent($employee, $this->googleEventId);
            if (! $result->ok) {
                throw new RuntimeException("Calendar delete failed (outbox #{$result->outboxId}).");
            }
        }

        if ($this->workItemId) {
            // Scoped by the event id we just deleted: if a later upsert already wrote a
            // newer event id onto this row, don't clobber it.
            WorkItem::withoutGlobalScopes()->where('id', $this->workItemId)
                ->where('google_event_id', $this->googleEventId)
                ->update(['google_event_id' => null, 'calendar_version' => null]);
        }
    }

    /** No connection, nothing to mirror: silently done, not a failure to retry. */
    private function connected(Employee $employee): bool
    {
        return GoogleCalendarConnection::where('user_id', $employee->user_id)->exists();
    }
}
