<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\AuditLog;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AssetController extends Controller
{
    private const PRIVILEGED_ROLES = ['management', 'hr'];

    public function store(Request $request): RedirectResponse
    {
        $this->authorizePrivileged($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'category' => ['required', 'in:laptop,phone,vehicle,furniture,other'],
            'serial' => ['nullable', 'string', 'max:80'],
        ]);

        Asset::create([
            'tenant_id' => app(CurrentTenant::class)->id(),
            'name' => $data['name'],
            'category' => $data['category'],
            'serial' => $data['serial'] ?? null,
            'status' => 'available',
        ]);

        AuditLog::record('Added asset', $data['name']);

        return back()->with('ok', $data['name'].' added to the register.');
    }

    public function assign(Request $request, Asset $asset): RedirectResponse
    {
        $this->authorizePrivileged($request);
        abort_unless($asset->tenant_id === app(CurrentTenant::class)->id(), 403);
        $tenantId = app(CurrentTenant::class)->id();

        $data = $request->validate([
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')->where('tenant_id', $tenantId)],
        ]);

        $asset->update([
            'employee_id' => $data['employee_id'],
            'status' => 'assigned',
            'assigned_at' => now()->toDateString(),
        ]);

        AuditLog::record('Assigned asset', $asset->name.' → '.($asset->employee?->name ?? 'employee'));

        return back()->with('ok', $asset->name.' assigned.');
    }

    public function release(Request $request, Asset $asset): RedirectResponse
    {
        $this->authorizePrivileged($request);
        abort_unless($asset->tenant_id === app(CurrentTenant::class)->id(), 403);

        $asset->update(['employee_id' => null, 'status' => 'available', 'assigned_at' => null]);

        AuditLog::record('Returned asset', $asset->name);

        return back()->with('ok', $asset->name.' returned to the pool.');
    }

    private function authorizePrivileged(Request $request): void
    {
        $this->authorizeTenantRole($request, self::PRIVILEGED_ROLES);
    }
}
