<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\EapResource;
use App\Models\Employee;
use App\Models\WellnessCheckin;
use App\Models\WellnessRequest;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Wellness / EAP — employee wellbeing support. CONFIDENTIALITY is the core concern.
 *
 * Three parts: a private wellness PULSE (own-only history; HR sees anonymous
 * aggregates only), an EAP resource LIBRARY (browse-all, HR-managed catalogue),
 * and confidential 1:1 REQUESTS to HR (visible to the requester + HR only).
 *
 * Confidentiality is enforced at the DATA layer here, never merely in the template:
 *  - Every role opens this screen and sees only its own slice.
 *  - An employee gets their OWN check-ins + own requests + the catalogue.
 *  - HR/management get AGGREGATE pulse trends (no individual rows, no names tied to
 *    scores), the requests inbox, and catalogue management.
 *  - A plain manager gets NEITHER aggregates NOR others' requests — wellbeing is
 *    HR-confidential. Both the aggregate view and the requests inbox are gated to
 *    management/hr (HR is the intent; management is included for a small-org owner
 *    who also runs people matters — documented and kept consistent across read +
 *    resolve so there is one privilege boundary, not two).
 */
class WellnessController extends Controller
{
    /**
     * Who may see anonymous aggregate pulse trends, the identified 1:1 requests
     * inbox, and manage the EAP catalogue. Plain employees and plain managers do not.
     */
    private const PRIVILEGED_ROLES = ['management', 'hr'];

    private const CATEGORIES = ['Mental Health', 'Financial', 'Physical', 'Legal', 'Hotline'];

    private const URGENCIES = ['low', 'normal', 'high'];

    private const REQUEST_STATUSES = ['open', 'acknowledged', 'closed'];

    /**
     * Build the wellness screen data. Tenant scope is automatic via BelongsToTenant.
     *
     * Confidentiality is resolved entirely here:
     *  - The active EAP catalogue is visible to everyone.
     *  - The acting employee always gets their OWN check-in history and OWN requests.
     *  - management/hr additionally get anonymized aggregate pulse trends (avg /
     *    distribution / count — never per-person rows) and catalogue management.
     *  - hr additionally gets the identified 1:1 requests inbox.
     * A plain manager gets aggregates? NO — only management/hr; the inbox is hr-only.
     *
     * @return array<string, mixed>
     */
    public function screenData(Request $request, ?Employee $employee): array
    {
        $role = $request->attributes->get('tenantRole', 'employee');
        $privileged = in_array($role, self::PRIVILEGED_ROLES, true);

        // Browsable by everyone — active resources only, hotlines surfaced first.
        $resources = EapResource::where('is_active', true)
            ->orderByRaw("CASE WHEN category = 'Hotline' THEN 0 ELSE 1 END")
            ->orderBy('category')->orderBy('title')->get();

        // The acting employee's OWN slice — never anyone else's.
        $myCheckins = $employee
            ? WellnessCheckin::where('employee_id', $employee->id)
                ->orderByDesc('checkin_date')->orderByDesc('id')->limit(14)->get()
            : new Collection;

        $myRequests = $employee
            ? WellnessRequest::where('employee_id', $employee->id)
                ->orderByDesc('created_at')->get()
            : new Collection;

        return [
            'role' => $role,
            'privileged' => $privileged,
            'canCheckin' => (bool) $employee,
            'resources' => $resources,
            'myCheckins' => $myCheckins,
            'myRequests' => $myRequests,
            // Anonymous aggregate trends — management/hr only, no individual rows.
            'aggregate' => $privileged ? $this->aggregate() : null,
            // Identified 1:1 inbox — management/hr only.
            'inbox' => $privileged
                ? WellnessRequest::with(['employee', 'handledBy'])->orderByDesc('created_at')->get()
                : new Collection,
            'categories' => self::CATEGORIES,
            'urgencies' => self::URGENCIES,
        ];
    }

    /** Employee logs their OWN pulse check-in. employee_id is always the actor's. */
    public function checkin(Request $request): RedirectResponse
    {
        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403, 'No employee profile in this workspace.');

        $data = $request->validate([
            'mood' => ['required', 'integer', 'min:1', 'max:5'],
            'stress' => ['required', 'integer', 'min:1', 'max:5'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        // tenant_id is auto-filled by BelongsToTenant. The check-in is bound to the
        // acting employee only — never a passed-in id — so nobody can log for another.
        WellnessCheckin::create([
            'employee_id' => $employee->id,
            'mood' => $data['mood'],
            'stress' => $data['stress'],
            'note' => $data['note'] ?? null,
            'checkin_date' => now()->toDateString(),
        ]);

        // Confidentiality: we deliberately do NOT audit-log the check-in payload
        // (mood/stress/note). A private pulse must not leak into the audit trail.

        return back()->with('ok', 'Thanks for checking in — your entry is private to you.');
    }

    /** Employee requests a confidential 1:1 with HR. employee_id is always the actor's. */
    public function requestSession(Request $request): RedirectResponse
    {
        $employee = $request->attributes->get('employee');
        abort_unless($employee, 403, 'No employee profile in this workspace.');

        $data = $request->validate([
            'topic' => ['nullable', Rule::in(self::CATEGORIES)],
            'message' => ['required', 'string', 'max:2000'],
            'urgency' => ['required', Rule::in(self::URGENCIES)],
        ]);

        WellnessRequest::create([
            'employee_id' => $employee->id,
            'topic' => $data['topic'] ?? null,
            'message' => $data['message'],
            'urgency' => $data['urgency'],
            'status' => 'open',
        ]);

        // No payload in the audit trail — only that a request was raised, to keep the
        // content confidential to the requester and HR.
        AuditLog::record('Raised wellness request', ucfirst($data['urgency']).' urgency');

        return back()->with('ok', 'Your request was sent confidentially to HR.');
    }

    /** HR/management resolve a 1:1 request (acknowledge or close). Inbox is hr-only. */
    public function resolveRequest(Request $request, WellnessRequest $req): RedirectResponse
    {
        abort_unless($this->isPrivileged($request), 403, 'Only HR and management can manage wellness requests.');
        abort_unless($req->tenant_id === app(CurrentTenant::class)->id(), 403);

        $data = $request->validate([
            'status' => ['required', Rule::in(['acknowledged', 'closed'])],
        ]);

        $handler = $request->attributes->get('employee');

        $req->update([
            'status' => $data['status'],
            'handled_by_id' => $handler?->id,
            'handled_at' => now(),
        ]);

        AuditLog::record('Resolved wellness request', '#'.$req->id.' · '.$data['status']);

        return back()->with('ok', 'Request marked '.$data['status'].'.');
    }

    /** Privileged only: add a resource to the EAP catalogue. */
    public function storeResource(Request $request): RedirectResponse
    {
        abort_unless($this->isPrivileged($request), 403, 'Only HR and management can manage EAP resources.');

        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'category' => ['required', Rule::in(self::CATEGORIES)],
            'description' => ['required', 'string', 'max:2000'],
            'contact' => ['nullable', 'string', 'max:160'],
            'url' => ['nullable', 'url', 'max:300'],
        ]);

        $resource = EapResource::create([
            'tenant_id' => app(CurrentTenant::class)->id(),
            'title' => $data['title'],
            'category' => $data['category'],
            'description' => $data['description'],
            'contact' => $data['contact'] ?? null,
            'url' => $data['url'] ?? null,
            'is_active' => true,
        ]);

        AuditLog::record('Added EAP resource', $resource->title.' · '.$resource->category);

        return back()->with('ok', $resource->title.' added to the EAP library.');
    }

    /**
     * Anonymized pulse aggregate for management/hr. Computed in PHP off tenant-scoped
     * rows so NO individual entry, note, or employee identity ever escapes — only
     * averages, counts, and a 1–5 distribution. Guards an empty dataset.
     *
     * @return array<string, mixed>
     */
    private function aggregate(): array
    {
        // Aggregate at the DB layer — never pull individual check-in rows into PHP.
        // Besides the obvious memory win at scale, hydrating rows risks a future
        // maintainer lazy-loading ->employee and de-anonymising confidential data.
        $count = WellnessCheckin::query()->count();

        return [
            'count' => $count,
            'participants' => WellnessCheckin::query()->distinct()->count('employee_id'),
            'avgMood' => $count ? round((float) WellnessCheckin::query()->avg('mood'), 1) : null,
            'avgStress' => $count ? round((float) WellnessCheckin::query()->avg('stress'), 1) : null,
            'moodDist' => $this->distribution('mood'),
            'stressDist' => $this->distribution('stress'),
        ];
    }

    /**
     * A 1–5 count distribution for a pulse field, computed with a single GROUP BY.
     * Always returns every bucket 1..5 (zero-filled) so the chart shape is stable.
     *
     * @return array<int, int>
     */
    private function distribution(string $field): array
    {
        $counts = WellnessCheckin::query()
            ->select($field, DB::raw('COUNT(*) as c'))
            ->groupBy($field)
            ->pluck('c', $field);

        return (new Collection(range(1, 5)))
            ->mapWithKeys(fn (int $n) => [$n => (int) ($counts[$n] ?? 0)])
            ->all();
    }

    private function isPrivileged(Request $request): bool
    {
        return $this->hasTenantRole($request, self::PRIVILEGED_ROLES);
    }
}
