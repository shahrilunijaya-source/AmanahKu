<?php

declare(strict_types=1);

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\AttendanceAttempt;
use Illuminate\Http\Request;
use Illuminate\View\View as ViewContract;

/**
 * Read the recorded clock attempts. Reachable only behind the super.admin guard.
 *
 * The companion to the error console: that one holds faults, this one holds punches the
 * server refused on purpose. A refusal is not a fault, so it appears in no log and no
 * error tracker — this is the only place "why could this person not clock in" is
 * answerable without asking them. Read-only; the nightly prune in bootstrap/app.php
 * keeps the table small.
 */
class AttendanceAttemptController extends Controller
{
    /** Recent attempts, newest first, refusals only by default. */
    public function index(Request $request): ViewContract
    {
        // Refusals first, because a list dominated by successful punches buries the two
        // rows worth reading. `?outcome=all` opens it up for the desktop-versus-phone
        // comparison, which needs the successes as well.
        $outcome = (string) $request->query('outcome', 'refused');

        $attempts = AttendanceAttempt::query()
            ->with(['user:id,name', 'tenant:id,name'])
            ->when($outcome === 'refused', fn ($query) => $query->where('outcome', '!=', 'ok'))
            ->when(! in_array($outcome, ['refused', 'all'], true), fn ($query) => $query->where('outcome', $outcome))
            ->latest('created_at')
            ->paginate(50)
            ->withQueryString();

        return view('superadmin.attendance-attempts.index', [
            'attempts' => $attempts,
            'outcome' => $outcome,
            // What the host actually allows an upload to be. The app's own rule is 4MB,
            // but PHP refuses first if its limits are lower, and on production these
            // values are set by whoever owns the host — not by this repo. A phone camera
            // selfie sits at 3-8MB, so a 2M limit here is the whole bug.
            'uploadMax' => (string) ini_get('upload_max_filesize'),
            'postMax' => (string) ini_get('post_max_size'),
        ]);
    }
}
