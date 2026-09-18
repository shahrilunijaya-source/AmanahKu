<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CompanyEvent;
use App\Models\Employee;
use App\Models\GoogleCalendarConnection;
use App\Models\WorkItem;
use App\Ports\CalendarPort;
use App\Support\Calendar\CalendarReconciler;
use App\Support\Calendar\CalendarSyncProgress;
use App\Support\Calendar\CompanyEventCopies;
use App\Support\Calendar\TaggedCopies;
use App\Tenancy\CurrentTenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * "Sync now", and the first sync after (re)connecting: push every open dated card the
 * person owns or is tagged on, in every company they belong to, then pull Google's
 * changes. One card failing does not stop the rest; revoked access does.
 */
class CalendarFullSyncJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 600;

    /** The queue's retry_after must be longer than this, or a second worker picks the job up mid-run. */
    public int $timeout = 900;

    public function __construct(public readonly int $userId) {}

    public function uniqueId(): string
    {
        return (string) $this->userId;
    }

    public function handle(CurrentTenant $context, CalendarPort $port, CalendarReconciler $reconciler): void
    {
        $connection = GoogleCalendarConnection::where('user_id', $this->userId)->whereNull('revoked_at')->first();
        if (! $connection) {
            CalendarSyncProgress::finish($this->userId, 'expired');

            return;
        }

        $targets = $this->targets();
        CalendarSyncProgress::start($this->userId, count($targets));

        try {
            foreach ($targets as $target) {
                $job = $target['kind'] === 'event'
                    ? new SyncCompanyEventCalendarCopyJob(tenantId: $target['tenantId'], action: 'upsert', companyEventId: $target['id'], employeeId: $target['recipient'])
                    : new SyncWorkItemCalendarEventJob(tenantId: $target['tenantId'], action: 'upsert', workItemId: $target['id'], recipientEmployeeId: $target['recipient']);
                try {
                    $job->handle($context, $port);
                    CalendarSyncProgress::tick($this->userId, true);
                } catch (Throwable $e) {
                    $job->failed($e);
                    CalendarSyncProgress::tick($this->userId, false);
                    if ($connection->fresh()?->revoked_at) {
                        CalendarSyncProgress::finish($this->userId, 'expired');

                        return;
                    }
                }
            }

            $pulled = (new PullCalendarChangesJob($connection->id))->handle($context, $port, $reconciler);
            CalendarSyncProgress::finish($this->userId, 'done', $pulled);
        } catch (Throwable $e) {
            CalendarSyncProgress::finish($this->userId, 'done', 0);
            throw $e;
        }
    }

    /** Killed or timed out on the queue: don't leave the board's spinner stuck on "running". */
    public function failed(?Throwable $e): void
    {
        CalendarSyncProgress::finish($this->userId, 'done');
    }

    /** @return list<array{tenantId: int, kind: 'work_item'|'event', id: int, recipient: ?int}> recipient null = card owner */
    private function targets(): array
    {
        $out = [];
        $open = fn (Builder $q) => $q->whereNotNull('due_at')->whereNull('parent_id')
            ->whereNull('archived_at')->whereNull('cancelled_at')->where('status', '!=', 'done');

        foreach (Employee::withoutGlobalScope('tenant')->where('user_id', $this->userId)->get() as $employee) {
            $owned = WorkItem::withoutGlobalScopes()->where('employee_id', $employee->id)->tap($open)
                ->whereNull('company_event_id')->pluck('id');
            foreach ($owned as $id) {
                $out[] = ['tenantId' => $employee->tenant_id, 'kind' => 'work_item', 'id' => $id, 'recipient' => null];
            }

            $tagged = WorkItem::withoutGlobalScopes()->tap($open)
                ->where('employee_id', '!=', $employee->id)
                ->whereNull('company_event_id')
                ->whereIn('id', fn ($q) => $q->select('work_item_id')->from('work_item_participant')
                    ->where('employee_id', $employee->id)
                    ->where(fn ($r) => $r->whereIn('role', array_filter(TaggedCopies::ROLES))->orWhereNull('role')))
                ->pluck('id');
            foreach ($tagged as $id) {
                $out[] = ['tenantId' => $employee->tenant_id, 'kind' => 'work_item', 'id' => $id, 'recipient' => $employee->id];
            }

            $events = CompanyEvent::withoutGlobalScopes()->where('tenant_id', $employee->tenant_id)
                ->get()->reject(fn (CompanyEvent $e) => $e->isOver())
                ->filter(fn (CompanyEvent $e) => CompanyEventCopies::recipients($e)->contains('id', $employee->id));
            foreach ($events as $event) {
                $out[] = ['tenantId' => $employee->tenant_id, 'kind' => 'event', 'id' => $event->id, 'recipient' => $employee->id];
            }
        }

        return $out;
    }
}
