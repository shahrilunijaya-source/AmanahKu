<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    /** Roles allowed to see and manage every employee's documents. */
    private const PRIVILEGED_ROLES = ['management', 'hr'];

    /** Allowed document categories — mirrored in validation + the model enum. */
    private const CATEGORIES = ['Contract', 'Certificate', 'ID', 'Other'];

    /** The private disk all documents live on. Never exposed via a public URL. */
    private const DISK = 'local';

    /**
     * Build only the data the documents screen needs.
     *
     * Privileged roles (management/hr) get every document in the tenant grouped by
     * category, with owners eager-loaded, plus an employee list for the upload
     * picker. A plain employee only ever sees their own documents. Tenant scope is
     * applied automatically by the BelongsToTenant global scope.
     */
    public function screenData(Request $request, ?Employee $employee): array
    {
        $privileged = $this->isPrivileged($request);

        $query = EmployeeDocument::with('employee')->latest();

        if (! $privileged) {
            // Non-privileged: hard-scope to the viewer's own documents server-side,
            // never just hide other rows at the template layer.
            $query->where('employee_id', $employee?->id ?? 0);
        }

        return [
            'privileged' => $privileged,
            'documents' => $query->get()->groupBy('category'),
            // Owner picker is only used by the privileged-only upload form.
            'employees' => $privileged
                ? Employee::active()->orderBy('name')->get(['id', 'name'])
                : collect(),
            'categories' => self::CATEGORIES,
        ];
    }

    /** Upload a document and persist its metadata. */
    public function store(Request $request): RedirectResponse
    {
        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403, 'No employee profile in this workspace.');

        $privileged = $this->isPrivileged($request);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'category' => ['required', 'in:'.implode(',', self::CATEGORIES)],
            'employee_id' => ['required', 'integer'],
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:8192'],
        ]);

        // Owner gating: privileged may upload for anyone in the tenant; a plain
        // employee may only upload to themselves. Enforce both server-side.
        $ownerId = (int) $data['employee_id'];
        if (! $privileged) {
            $ownerId = $employee->id;
        }
        $owner = Employee::find($ownerId);
        abort_unless($owner, 422, 'Unknown document owner.');

        $file = $request->file('file');
        $path = $file->store('employee-documents', self::DISK);
        abort_unless($path !== false, 500, 'File could not be stored.');

        EmployeeDocument::create([
            'employee_id' => $owner->id,
            'title' => $data['title'],
            'category' => $data['category'],
            'file_path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime' => $file->getMimeType(),
            'size' => $file->getSize(),
            'uploaded_by_employee_id' => $employee->id,
        ]);

        AuditLog::record('Uploaded document', $owner->name.' · '.$data['title']);

        return back()->with('ok', 'Document uploaded for '.$owner->name.'.');
    }

    /** Stream a stored document through an auth-gated action (never a public URL). */
    public function download(Request $request, EmployeeDocument $document): StreamedResponse
    {
        $this->authorizeAccess($request, $document);
        abort_unless(Storage::disk(self::DISK)->exists($document->file_path), 404);

        return Storage::disk(self::DISK)->download($document->file_path, $document->original_name);
    }

    /** Delete the stored file and its metadata row. */
    public function destroy(Request $request, EmployeeDocument $document): RedirectResponse
    {
        $this->authorizeAccess($request, $document);

        Storage::disk(self::DISK)->delete($document->file_path);
        $title = $document->title;
        $ownerName = $document->employee?->name ?? 'employee';
        $document->delete();

        AuditLog::record('Deleted document', $ownerName.' · '.$title);

        return back()->with('ok', 'Document deleted.');
    }

    /** Privileged roles, OR the document's own owner, may access it. Tenant-asserted. */
    private function authorizeAccess(Request $request, EmployeeDocument $document): void
    {
        abort_unless($document->tenant_id === app(CurrentTenant::class)->id(), 403);

        if ($this->isPrivileged($request)) {
            return;
        }

        $employee = $request->attributes->get('employee');
        abort_unless($employee && $document->employee_id === $employee->id, 403);
    }

    private function isPrivileged(Request $request): bool
    {
        return $this->hasTenantRole($request, self::PRIVILEGED_ROLES);
    }
}
