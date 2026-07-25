<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AppNotification;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /** Mark all of the current user's unread notifications (in this tenant) as read. */
    public function markRead(Request $request): RedirectResponse
    {
        AppNotification::where('user_id', $request->user()->id)
            ->where('tenant_id', app(CurrentTenant::class)->id())   // explicit, not just the global scope
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return back();
    }

    /**
     * Poll target for the browser-notification client: unread bells newer than the
     * cursor the caller last saw, plus the current high-water id to store as the next
     * cursor. Capped at 5 so a long-idle tab raises a few alerts, not a burst.
     */
    public function unseen(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $tenantId = app(CurrentTenant::class)->id();
        $since = (int) $request->query('since', '0');

        $base = fn () => AppNotification::where('user_id', $userId)
            ->where('tenant_id', $tenantId);   // explicit, not just the global scope

        return response()->json([
            'notifications' => $base()
                ->whereNull('read_at')
                ->where('id', '>', $since)
                ->orderByDesc('id')
                ->limit(5)
                ->get(['id', 'title', 'body', 'url']),
            'latestId' => (int) ($base()->max('id') ?? 0),
        ]);
    }
}
