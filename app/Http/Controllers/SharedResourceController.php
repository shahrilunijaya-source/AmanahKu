<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\SharedResource;
use App\Tenancy\CurrentTenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class SharedResourceController extends Controller
{
    private const PRIVILEGED_ROLES = ['manager', 'management', 'hr'];

    private const CATEGORIES = ['email', 'design', 'comms', 'system', 'storage', 'other'];

    /**
     * Build the shared-resources screen data. Tenant scope is automatic via
     * BelongsToTenant, so every staff member sees this workspace's resources.
     * `canManage` gates the add/edit/delete UI to privileged roles only — the
     * server still re-checks the role in every write action.
     *
     * @return array<string, mixed>
     */
    public function screenData(Request $request): array
    {
        $resources = SharedResource::orderBy('sort_order')->orderBy('name')->get();

        // grouped[category] = resources in that category, preserving CATEGORIES order
        // and dropping empty categories so the view only renders populated sections.
        $grouped = (new Collection(self::CATEGORIES))
            ->mapWithKeys(fn (string $c) => [$c => $resources->where('category', $c)->values()])
            ->filter(fn (Collection $items) => $items->isNotEmpty());

        return [
            'resources' => $resources,
            'grouped' => $grouped,
            'categories' => self::CATEGORIES,
            'canManage' => $this->hasTenantRole($request, self::PRIVILEGED_ROLES),
        ];
    }

    /** Privileged only: add a shared resource. */
    public function store(Request $request): RedirectResponse
    {
        $this->authorizeTenantRole($request, self::PRIVILEGED_ROLES);

        $data = $this->validateData($request);

        SharedResource::create($data);

        AuditLog::record('Added shared resource', $data['name']);

        return back()->with('ok', 'Shared resource added — '.$data['name'].'.');
    }

    /** Privileged only: edit a shared resource in the active tenant. */
    public function update(Request $request, SharedResource $resource): RedirectResponse
    {
        $this->authorizeTenantRole($request, self::PRIVILEGED_ROLES);
        abort_unless($resource->tenant_id === app(CurrentTenant::class)->id(), 403);

        $data = $this->validateData($request);

        $resource->update($data);

        AuditLog::record('Updated shared resource', $resource->name);

        return back()->with('ok', 'Shared resource updated — '.$resource->name.'.');
    }

    /** Privileged only: delete a shared resource in the active tenant. */
    public function destroy(Request $request, SharedResource $resource): RedirectResponse
    {
        $this->authorizeTenantRole($request, self::PRIVILEGED_ROLES);
        abort_unless($resource->tenant_id === app(CurrentTenant::class)->id(), 403);

        $name = $resource->name;
        $resource->delete();

        AuditLog::record('Deleted shared resource', $name);

        return back()->with('ok', 'Shared resource deleted — '.$name.'.');
    }

    /**
     * Shared validation for store/update. Credentials are optional — a resource may
     * be a link-only entry with no login, or a login with no public URL.
     *
     * @return array<string, mixed>
     */
    private function validateData(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'category' => ['required', Rule::in(self::CATEGORIES)],
            'url' => ['nullable', 'url', 'max:255'],
            'username' => ['nullable', 'string', 'max:160'],
            'password' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);
    }
}
