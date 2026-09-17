<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\CalendarFullSyncJob;
use App\Jobs\SyncWorkItemCalendarEventJob;
use App\Models\GoogleCalendarConnection;
use App\Models\WorkItem;
use App\Models\WorkItemCalendarCopy;
use App\Services\GoogleCalendarClient;
use App\Support\Calendar\CalendarSyncProgress;
use App\Support\Calendar\CalendarSyncStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The task board's Google Calendar control. JSON only; the board swaps its own panel.
 * Sync now and Retry share one per-person limit so the button cannot be hammered.
 */
class CalendarSyncController extends Controller
{
    public const LIMIT_SECONDS = 120;

    public function __construct(GoogleCalendarClient $client)
    {
        abort_unless($client->configured(), 404);
    }

    public static function limiterKey(int $userId): string
    {
        return "calendar-sync:{$userId}";
    }

    public function status(Request $request): JsonResponse
    {
        return response()->json(CalendarSyncStatus::for($request->user()));
    }

    public function sync(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! GoogleCalendarConnection::where('user_id', $user->id)->whereNull('revoked_at')->exists()) {
            return response()->json(['message' => 'Connect Google Calendar first.'], 409);
        }
        if ($blocked = $this->limited($user->id)) {
            return $blocked;
        }

        CalendarSyncProgress::start($user->id, 0);
        CalendarFullSyncJob::dispatch($user->id);

        return response()->json(CalendarSyncStatus::for($user), 202);
    }

    public function retry(Request $request, WorkItem $workItem): JsonResponse
    {
        $employee = $request->attributes->get('employee');
        // Route binding ignores tenants: check this card is in the caller's company.
        abort_unless($employee && $workItem->tenant_id === $employee->tenant_id, 403);

        $isOwner = $workItem->employee_id === $employee->id;
        $copy = $isOwner ? null : WorkItemCalendarCopy::where('work_item_id', $workItem->id)->where('employee_id', $employee->id)->first();
        abort_unless($isOwner || $copy, 403);

        if ($blocked = $this->limited($request->user()->id)) {
            return $blocked;
        }

        if ($isOwner) {
            WorkItem::withoutGlobalScopes()->where('id', $workItem->id)->update(['calendar_sync_error' => null]);
            SyncWorkItemCalendarEventJob::dispatch(tenantId: $workItem->tenant_id, action: 'upsert', workItemId: $workItem->id);
        } else {
            $copy->update(['sync_error' => null]);
            SyncWorkItemCalendarEventJob::dispatch(tenantId: $workItem->tenant_id, action: 'upsert', workItemId: $workItem->id, recipientEmployeeId: $employee->id);
        }

        return response()->json(CalendarSyncStatus::for($request->user()));
    }

    private function limited(int $userId): ?JsonResponse
    {
        $key = self::limiterKey($userId);
        if (RateLimiter::tooManyAttempts($key, 1)) {
            $wait = RateLimiter::availableIn($key);

            return response()->json(['message' => "You can sync again in {$wait} seconds.", 'retry_after' => $wait], 429);
        }
        RateLimiter::hit($key, self::LIMIT_SECONDS);

        return null;
    }
}
