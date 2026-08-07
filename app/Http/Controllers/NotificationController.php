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

        // Compute the ceiling before the rows query. Otherwise a row inserted in the gap
        // between the two queries would be counted in latestId (advancing the client's
        // cursor past it) but never appear in the rows, and would be lost for good.
        $max = (int) ($base()->max('id') ?? 0);

        return response()->json([
            'notifications' => $base()
                ->whereNull('read_at')
                ->where('id', '>', $since)
                ->where('id', '<=', $max)
                ->orderByDesc('id')
                ->limit(5)
                ->get(['id', 'title', 'body', 'url']),
            'latestId' => $max,
        ]);
    }

    /**
     * Poll target for the live header bell: the same latest-8, mixed-read-state
     * snapshot the header composer renders on first paint (AppServiceProvider),
     * flattened to JSON so a poll tick can fully replace the bell's client state.
     * Unlike unseen(), this is not cursor-based and includes already-read rows.
     */
    public function summary(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $tenantId = app(CurrentTenant::class)->id();

        // orderByDesc('id'), not latest(): created_at ties on same-second inserts and
        // sorts non-deterministically, same reasoning as unseen() above.
        $notifications = AppNotification::where('user_id', $userId)
            ->where('tenant_id', $tenantId)   // explicit, not just the global scope
            ->orderByDesc('id')
            ->take(8)
            ->get();

        // A second query, not a count over $notifications: the true unread total is
        // never bounded by the 8-row page, matching the composer's own approach.
        $unread = AppNotification::where('user_id', $userId)->where('tenant_id', $tenantId)->whereNull('read_at')->count();

        return response()->json([
            'unread' => $unread,
            'notifications' => $notifications->map(fn (AppNotification $n) => [
                'id' => $n->id,
                'title' => $n->title,
                'body' => $n->body,
                'url' => $n->url,
                'read_at' => $n->read_at !== null,
                'at' => $n->created_at->diffForHumans(),
            ])->values(),
        ]);
    }
}
