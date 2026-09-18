<?php

declare(strict_types=1);

namespace App\Support\Calendar;

use App\Http\Controllers\CalendarSyncController;
use App\Models\CompanyEventCalendarCopy;
use App\Models\Employee;
use App\Models\GoogleCalendarConnection;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkItemCalendarCopy;
use App\Services\GoogleCalendarClient;
use Illuminate\Support\Facades\RateLimiter;

/** Everything the board's Google Calendar control shows, for one person, across their companies. */
final class CalendarSyncStatus
{
    /**
     * @param  int|null  $tenantId  limit cards and copies to this company (the one Retry accepts)
     * @return array<string, mixed>
     */
    public static function for(User $user, ?int $tenantId = null): array
    {
        $connection = GoogleCalendarConnection::where('user_id', $user->id)->first();
        $employeeIds = Employee::withoutGlobalScope('tenant')->where('user_id', $user->id)
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->pluck('id');

        $state = match (true) {
            $connection === null => 'off',
            $connection->revoked_at !== null => 'expired',
            default => 'connected',
        };

        $ownerIssues = WorkItem::withoutGlobalScopes()->whereIn('employee_id', $employeeIds)
            ->whereNotNull('calendar_sync_error')->orderByDesc('updated_at')
            ->get(['id', 'title', 'calendar_sync_error'])
            ->map(fn (WorkItem $w) => ['id' => $w->id, 'title' => $w->title, 'message' => $w->calendar_sync_error, 'kind' => 'card']);

        $copyIssues = WorkItemCalendarCopy::with('workItem:id,title')->whereIn('employee_id', $employeeIds)
            ->whereNotNull('sync_error')->orderByDesc('updated_at')->get()
            ->filter(fn (WorkItemCalendarCopy $c) => $c->workItem !== null)
            ->map(fn (WorkItemCalendarCopy $c) => ['id' => $c->work_item_id, 'title' => $c->workItem->title, 'message' => $c->sync_error, 'kind' => 'card']);

        $eventIssues = CompanyEventCalendarCopy::with('companyEvent:id,title')->whereIn('employee_id', $employeeIds)
            ->whereNotNull('sync_error')->orderByDesc('updated_at')->get()
            ->filter(fn (CompanyEventCalendarCopy $c) => $c->companyEvent !== null)
            ->map(fn (CompanyEventCalendarCopy $c) => ['id' => 'event-'.$c->company_event_id, 'title' => $c->companyEvent->title, 'message' => $c->sync_error, 'kind' => 'event']);

        $mirrored = WorkItem::withoutGlobalScopes()->whereIn('employee_id', $employeeIds)->whereNotNull('google_event_id')->count()
            + WorkItemCalendarCopy::whereIn('employee_id', $employeeIds)->whereNotNull('google_event_id')->count()
            + CompanyEventCalendarCopy::whereIn('employee_id', $employeeIds)->whereNotNull('google_event_id')->count();

        $key = CalendarSyncController::limiterKey($user->id);

        return [
            'configured' => app(GoogleCalendarClient::class)->configured(),
            'state' => $state,
            'last_synced_at' => $connection?->last_pulled_at?->toIso8601String(),
            'last_synced_human' => $connection?->last_pulled_at?->diffForHumans(),
            'mirrored' => $mirrored,
            'issues' => $ownerIssues->concat($copyIssues)->concat($eventIssues)->unique('id')->values()->all(),
            'progress' => CalendarSyncProgress::get($user->id),
            'retry_after' => RateLimiter::tooManyAttempts($key, 1) ? RateLimiter::availableIn($key) : 0,
        ];
    }
}
