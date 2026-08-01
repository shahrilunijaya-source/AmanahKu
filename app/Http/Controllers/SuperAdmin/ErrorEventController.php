<?php

declare(strict_types=1);

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\ErrorEvent;
use Illuminate\Http\Request;
use Illuminate\View\View as ViewContract;

/**
 * Read the captured faults. Reachable only behind the super.admin guard.
 *
 * This is the whole reason ErrorEvent exists: production gives a developer an
 * application login and nothing else, so the console is the only window onto a
 * production stack trace. Read-only by design — nothing here deletes or edits a
 * row, and the daily prune in bootstrap/app.php is what keeps the table small.
 */
class ErrorEventController extends Controller
{
    /** Recent faults, newest first, searchable by the reference a user reported. */
    public function index(Request $request): ViewContract
    {
        $search = trim((string) $request->query('q', ''));

        $events = ErrorEvent::query()
            ->with(['user:id,name', 'tenant:id,name'])
            ->when($search !== '', fn ($query) => $query->where('reference', strtoupper($search)))
            ->latest('created_at')
            ->paginate(30)
            ->withQueryString();

        return view('superadmin.errors.index', [
            'events' => $events,
            'search' => $search,
        ]);
    }

    /** One fault in full, including the stack. */
    public function show(ErrorEvent $errorEvent): ViewContract
    {
        $errorEvent->load(['user:id,name,email', 'tenant:id,name,slug']);

        return view('superadmin.errors.show', [
            'event' => $errorEvent,
            // Same fault seen elsewhere. Tells you at a glance whether this is a
            // one-off or something every user is walking into.
            'repeats' => ErrorEvent::where('fingerprint', $errorEvent->fingerprint)->count(),
        ]);
    }
}
