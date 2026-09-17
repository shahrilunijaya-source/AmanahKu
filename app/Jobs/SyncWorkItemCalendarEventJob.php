<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Employee;
use App\Models\GoogleCalendarConnection;
use App\Models\Tenant;
use App\Models\WorkItem;
use App\Models\WorkItemCalendarCopy;
use App\Ports\CalendarPort;
use App\Support\Calendar\CalendarMirror;
use App\Support\Calendar\TaggedCopies;
use App\Tenancy\CurrentTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
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
        /** A tagged person's copy instead of the owner's entry. Null = the owner. */
        public readonly ?int $recipientEmployeeId = null,
    ) {}

    public function uniqueId(): string
    {
        return "{$this->action}:{$this->workItemId}:{$this->userId}:{$this->googleEventId}:{$this->recipientEmployeeId}";
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
        if (! $this->workItemId) {
            return;
        }
        $message = mb_substr($e?->getMessage() ?? 'Calendar sync failed', 0, 500);

        if ($this->recipientEmployeeId !== null) {
            // A first push that never succeeded has no row yet: create it to hold the error.
            WorkItemCalendarCopy::updateOrCreate(
                ['work_item_id' => $this->workItemId, 'employee_id' => $this->recipientEmployeeId],
                ['tenant_id' => $this->tenantId, 'sync_error' => $message],
            );

            return;
        }

        WorkItem::withoutGlobalScopes()->where('id', $this->workItemId)->update(['calendar_sync_error' => $message]);
    }

    /**
     * One push per card+person at a time, reading the card and copy inside the lock, so a
     * second push (queue, full sync, retry) sees the event id the first saved instead of
     * creating a duplicate Google event. A lock timeout throws: the queue retries it.
     */
    private function runUpsert(CalendarPort $port): void
    {
        Cache::lock("calendar-push:{$this->workItemId}:".($this->recipientEmployeeId ?? 'owner'), 30)
            ->block(10, fn () => $this->upsertLocked($port));
    }

    private function upsertLocked(CalendarPort $port): void
    {
        $item = WorkItem::withoutGlobalScopes()->find($this->workItemId);
        if (! $item || ! CalendarMirror::syncable($item)) {
            return;
        }

        if ($this->recipientEmployeeId !== null) {
            $this->upsertCopy($port, $item);

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

    private function upsertCopy(CalendarPort $port, WorkItem $item): void
    {
        $employee = Employee::withoutGlobalScope('tenant')->find($this->recipientEmployeeId);
        if (! $employee?->user_id || ! $this->connected($employee)) {
            return;
        }
        // Untagged between dispatch and run: nothing to send.
        if (! TaggedCopies::recipients($item)->contains('id', $employee->id)) {
            return;
        }

        $copy = WorkItemCalendarCopy::firstOrNew(
            ['work_item_id' => $item->id, 'employee_id' => $employee->id],
            ['tenant_id' => $item->tenant_id],
        );

        $result = $port->upsertEvent($employee, CalendarMirror::event($item, $copy));
        if (! $result->ok) {
            throw new RuntimeException("Calendar push failed (outbox #{$result->outboxId}).");
        }

        $copy->fill([
            'google_event_id' => $result->externalId,
            'calendar_version' => $result->payload['version'] ?? null,
            'sync_error' => null,
        ])->save();
    }

    private function runDelete(CalendarPort $port): void
    {
        if (! $this->userId || ! $this->googleEventId) {
            return;
        }

        if ($this->recipientEmployeeId !== null && $this->retaggedSince()) {
            return;
        }

        $employee = Employee::withoutGlobalScope('tenant')->where('user_id', $this->userId)->where('tenant_id', $this->tenantId)->first();
        if ($employee && $this->connected($employee)) {
            $result = $port->deleteEvent($employee, $this->googleEventId);
            if (! $result->ok) {
                throw new RuntimeException("Calendar delete failed (outbox #{$result->outboxId}).");
            }
        }

        if ($this->recipientEmployeeId !== null) {
            // Only the row still holding the event we just removed: a re-tag may have
            // written a newer one.
            WorkItemCalendarCopy::where('work_item_id', $this->workItemId)
                ->where('employee_id', $this->recipientEmployeeId)
                ->where('google_event_id', $this->googleEventId)
                ->delete();

            return;
        }

        if ($this->workItemId) {
            // Scoped by the event id we just deleted: if a later upsert already wrote a
            // newer event id onto this row, don't clobber it.
            WorkItem::withoutGlobalScopes()->where('id', $this->workItemId)
                ->where('google_event_id', $this->googleEventId)
                ->update(['google_event_id' => null, 'calendar_version' => null]);
        }
    }

    /** The person was tagged again after the untag that queued this delete: keep their copy. */
    private function retaggedSince(): bool
    {
        $item = WorkItem::withoutGlobalScopes()->find($this->workItemId);

        return $item !== null && CalendarMirror::syncable($item)
            && TaggedCopies::recipients($item)->contains('id', $this->recipientEmployeeId);
    }

    /** No live connection, nothing to mirror: silently done, not a failure to retry. */
    private function connected(Employee $employee): bool
    {
        return GoogleCalendarConnection::where('user_id', $employee->user_id)->whereNull('revoked_at')->exists();
    }
}
