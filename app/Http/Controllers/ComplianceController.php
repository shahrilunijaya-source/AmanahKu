<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\ComplianceItem;
use App\Models\Employee;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class ComplianceController extends Controller
{
    private const PRIVILEGED_ROLES = ['management', 'hr'];

    /**
     * Build the compliance / licence-tracking screen data. Tenant scope is automatic
     * via BelongsToTenant.
     *
     * Privileged roles (management/hr) see every item, expiry-bucket counts, and an
     * employee picker for the add form. Every other role sees only their own items
     * (filtered at the data layer) and an empty recipients list.
     *
     * @return array<string, mixed>
     */
    public function screenData(Request $request, ?Employee $employee): array
    {
        $privileged = $this->isPrivileged($request);

        $items = ($privileged
            ? ComplianceItem::with('employee')
            : ComplianceItem::with('employee')->where('employee_id', $employee?->id ?? 0))
            ->orderBy('expires_at')
            ->get();

        // Expiry-bucket summary strip — privileged only (it spans the whole workforce).
        $buckets = $privileged
            ? [
                'expired' => $items->where('expiry_bucket', 'expired')->count(),
                '30' => $items->where('expiry_bucket', '30')->count(),
                '60' => $items->where('expiry_bucket', '60')->count(),
                '90' => $items->where('expiry_bucket', '90')->count(),
                'valid' => $items->where('expiry_bucket', 'valid')->count(),
            ]
            : [];

        return [
            'items' => $items,
            'buckets' => $buckets,
            'recipients' => $privileged ? Employee::active()->orderBy('name')->get(['id', 'name']) : new Collection,
        ];
    }

    /** Create a compliance item for an employee. */
    public function store(Request $request): RedirectResponse
    {
        $this->authorizePrivileged($request);
        $tenantId = app(CurrentTenant::class)->id();

        $data = $request->validate([
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')->where('tenant_id', $tenantId)],
            'type' => ['required', 'in:license,certification,permit'],
            'name' => ['required', 'string', 'max:160'],
            'identifier' => ['nullable', 'string', 'max:120'],
            'issuer' => ['nullable', 'string', 'max:120'],
            'issued_at' => ['nullable', 'date'],
            'expires_at' => ['required', 'date'],
        ]);

        ComplianceItem::create([
            'tenant_id' => $tenantId,
            'employee_id' => $data['employee_id'],
            'type' => $data['type'],
            'name' => $data['name'],
            'identifier' => $data['identifier'] ?? null,
            'issuer' => $data['issuer'] ?? null,
            'issued_at' => $data['issued_at'] ?? null,
            'expires_at' => $data['expires_at'],
        ]);

        AuditLog::record('Added compliance item', $data['name']);

        return back()->with('ok', $data['name'].' added to the register.');
    }

    /** Bump the expiry date (renewal) and optionally re-stamp the issue date to today. */
    public function renew(Request $request, ComplianceItem $item): RedirectResponse
    {
        $this->authorizePrivileged($request);
        abort_unless($item->tenant_id === app(CurrentTenant::class)->id(), 403);

        $data = $request->validate([
            'expires_at' => ['required', 'date'],
            'reissue' => ['nullable', 'boolean'],
        ]);

        $item->update([
            'expires_at' => $data['expires_at'],
            'issued_at' => $request->boolean('reissue') ? now()->toDateString() : $item->issued_at,
        ]);

        AuditLog::record('Renewed compliance item', $item->name);

        return back()->with('ok', $item->name.' renewed.');
    }

    /** Delete a compliance item. */
    public function destroy(Request $request, ComplianceItem $item): RedirectResponse
    {
        $this->authorizePrivileged($request);
        abort_unless($item->tenant_id === app(CurrentTenant::class)->id(), 403);

        $name = $item->name;
        $item->delete();

        AuditLog::record('Deleted compliance item', $name);

        return back()->with('ok', $name.' removed.');
    }

    private function isPrivileged(Request $request): bool
    {
        return in_array($request->attributes->get('tenantRole', 'employee'), self::PRIVILEGED_ROLES, true);
    }

    private function authorizePrivileged(Request $request): void
    {
        abort_unless($this->isPrivileged($request), 403);
    }
}
