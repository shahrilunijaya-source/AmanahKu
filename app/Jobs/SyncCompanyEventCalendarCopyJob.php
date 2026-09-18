<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CompanyEvent;
use App\Models\CompanyEventCalendarCopy;
use App\Models\Employee;
use App\Models\GoogleCalendarConnection;
use App\Models\Tenant;
use App\Ports\CalendarPort;
use App\Support\Calendar\CalendarMirror;
use App\Support\Calendar\CompanyEventCopies;
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
 * Push/remove one non-attending employee's copy of a company event, same shape as
 * SyncWorkItemCalendarEventJob (CR-01's tagged-copy job) but for a company event
 * rather than a work item.
 */
class SyncCompanyEventCalendarCopyJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** @var list<int> seconds between retries: 1 min, 5 min, 15 min, 1 h */
    public array $backoff = [60, 300, 900, 3600];

    public function __construct(
        public readonly int $tenantId,
        public readonly string $action,
        public readonly int $companyEventId,
        public readonly int $employeeId,
        public readonly ?string $googleEventId = null,
    ) {}

    public function uniqueId(): string
    {
        return "{$this->action}:{$this->companyEventId}:{$this->employeeId}";
    }

    public function handle(CurrentTenant $context, CalendarPort $port): void
    {
        $tenant = Tenant::find($this->tenantId);
        if (! $tenant) {
            return;
        }

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
        $message = mb_substr($e?->getMessage() ?? 'Calendar sync failed', 0, 500);

        CompanyEventCalendarCopy::updateOrCreate(
            ['company_event_id' => $this->companyEventId, 'employee_id' => $this->employeeId],
            ['tenant_id' => $this->tenantId, 'sync_error' => $message],
        );
    }

    private function runUpsert(CalendarPort $port): void
    {
        Cache::lock("calendar-push:event:{$this->companyEventId}:{$this->employeeId}", 30)
            ->block(10, fn () => $this->upsertLocked($port));
    }

    private function upsertLocked(CalendarPort $port): void
    {
        $event = CompanyEvent::withoutGlobalScopes()->find($this->companyEventId);
        if (! $event || $event->isOver()) {
            return;
        }

        // Untagged/became an attendee between dispatch and run: nothing to send.
        if (! CompanyEventCopies::recipients($event)->contains('id', $this->employeeId)) {
            return;
        }

        $employee = Employee::withoutGlobalScope('tenant')->find($this->employeeId);
        if (! $employee?->user_id || ! $this->connected($employee)) {
            return;
        }

        $copy = CompanyEventCalendarCopy::firstOrNew(
            ['company_event_id' => $event->id, 'employee_id' => $employee->id],
            ['tenant_id' => $event->tenant_id],
        );

        $result = $port->upsertEvent($employee, CalendarMirror::companyEvent($event, $copy));
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
        if (! $this->googleEventId) {
            return;
        }

        // A newer push has already overwritten this copy with a different event id
        // (e.g. re-added as a recipient since the delete was queued): nothing to do.
        $current = CompanyEventCalendarCopy::where('company_event_id', $this->companyEventId)
            ->where('employee_id', $this->employeeId)->value('google_event_id');
        if ($current !== null && $current !== $this->googleEventId) {
            return;
        }

        $employee = Employee::withoutGlobalScope('tenant')->find($this->employeeId);
        if ($employee && $this->connected($employee)) {
            $result = $port->deleteEvent($employee, $this->googleEventId);
            if (! $result->ok) {
                throw new RuntimeException("Calendar delete failed (outbox #{$result->outboxId}).");
            }
        }

        // Only the row still holding the event we just removed: a re-add may have
        // already written a newer one.
        CompanyEventCalendarCopy::where('company_event_id', $this->companyEventId)
            ->where('employee_id', $this->employeeId)
            ->where('google_event_id', $this->googleEventId)
            ->delete();
    }

    /** No live connection, nothing to mirror: silently done, not a failure to retry. */
    private function connected(Employee $employee): bool
    {
        return GoogleCalendarConnection::where('user_id', $employee->user_id)->whereNull('revoked_at')->exists();
    }
}
