<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\LeaverBackfill;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * "Upload leaver dates" in the directory's Archived view: HR uploads a CSV of leavers'
 * dates, sees what would happen to each row, then applies it. Same rules as the
 * staff:backfill-leavers command (App\Services\LeaverBackfill). Gated by staff.delete,
 * the same permission as archiving one person by hand.
 */
class LeaverBackfillController extends Controller
{
    /** Check the uploaded file and show the preview. Writes nothing. */
    public function preview(Request $request, LeaverBackfill $backfill): RedirectResponse
    {
        $this->authorizeArchiving();
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:2048']]);

        $rows = LeaverBackfill::readCsv($request->file('file')->getRealPath());
        if (! $rows) {
            return back()->with('error', 'The file is empty or could not be read.');
        }

        return redirect('/app/directory?view=archived')->with('leaverPlan', $backfill->plan($rows));
    }

    /**
     * Apply the rows from the preview. Every row is checked again on the server, so the
     * posted rows carry no more trust than the uploaded file did.
     */
    public function apply(Request $request, LeaverBackfill $backfill): RedirectResponse
    {
        $this->authorizeArchiving();
        $request->validate([
            'rows' => ['required', 'array', 'max:1000'],
            'rows.*.name' => ['required', 'string', 'max:255'],
            'rows.*.last_working_day' => ['nullable', 'string', 'max:10'],
            'rows.*.resigned_on' => ['nullable', 'string', 'max:10'],
            'rows.*.joined_on' => ['nullable', 'string', 'max:10'],
        ]);

        $rows = array_values(array_map(fn (array $r) => [
            'name' => trim((string) $r['name']),
            'last_working_day' => (string) ($r['last_working_day'] ?? ''),
            'resigned_on' => (string) ($r['resigned_on'] ?? ''),
            'joined_on' => (string) ($r['joined_on'] ?? ''),
        ], $request->input('rows')));

        $done = $backfill->apply($rows);

        return redirect('/app/directory?view=archived')->with('ok', "Leaving dates recorded and archived for {$done} ".($done === 1 ? 'person.' : 'people.'));
    }

    /** Same gate as archiving one person by hand (EmployeeController::destroy). */
    private function authorizeArchiving(): void
    {
        $tenant = app(CurrentTenant::class)->get();
        abort_unless($tenant && request()->user()?->canInTenant($tenant, 'staff.delete'), 403);
    }
}
