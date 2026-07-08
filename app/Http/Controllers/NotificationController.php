<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AppNotification;
use App\Tenancy\CurrentTenant;
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
}
