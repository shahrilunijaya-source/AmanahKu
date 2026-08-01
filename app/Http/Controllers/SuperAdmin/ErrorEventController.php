<?php

declare(strict_types=1);

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\ErrorEvent;
use Illuminate\Http\Request;
use Illuminate\View\View as ViewContract;
use RuntimeException;

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

    /**
     * Throw on purpose, to prove the capture chain works on this environment.
     *
     * On production a developer holds a super-admin login and nothing else, so there is
     * no other way to answer "is error capture actually working here" — not before a
     * real fault arrives, which is the worst moment to find out it is not. Hitting this
     * should end at the standard error page carrying a reference that then resolves in
     * the list. It reads nothing and writes nothing but the fault it causes.
     */
    public function selfTest(): never
    {
        throw new RuntimeException('Error capture self-test. Nothing is broken; a super-admin asked for this fault.');
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
