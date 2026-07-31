<?php

declare(strict_types=1);

namespace App\Http\Controllers\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Services\FeatureManager;
use App\Support\Features;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View as ViewContract;

/**
 * Super-admin per-company feature matrix. Lets the platform operator set the
 * platform default + lock for every registry key, and (optionally) seed a
 * specific tenant override. Sits behind the super.admin guard alongside the
 * company provisioning console.
 */
class FeatureController extends Controller
{
    public function __construct(private readonly FeatureManager $features) {}

    /** The matrix page for one company. */
    public function show(Tenant $tenant): ViewContract
    {
        return view('superadmin.companies.features', $this->matrixData($tenant));
    }

    /**
     * Persist a platform default + lock for a single key, and optionally a tenant
     * override for the chosen company. Each key is validated against the registry.
     */
    public function update(Request $request, Tenant $tenant): RedirectResponse
    {
        $key = $request->input('key');
        abort_unless(is_string($key) && array_key_exists($key, Features::defaults()), 422);

        $validated = $request->validate([
            'platform_value' => ['required', $this->ruleFor($key)],
            'locked' => ['nullable'],
            'tenant_value' => ['nullable', $this->ruleFor($key)],
            'set_tenant' => ['nullable'],
        ]);

        $platformValue = $this->cast($key, $validated['platform_value']);
        $locked = (bool) $request->boolean('locked');

        $this->features->setPlatform($key, $platformValue, $locked);

        $note = 'platform default';

        // A tenant override is only meaningful when the key is not locked — a locked
        // key resolves to the platform value regardless. Reject the attempt loudly.
        if ($request->boolean('set_tenant') && array_key_exists('tenant_value', $validated) && $validated['tenant_value'] !== null) {
            if ($locked) {
                return back()->withErrors(['tenant_value' => 'Cannot set a company override on a locked feature.']);
            }
            $this->features->setTenant($tenant, $key, $this->cast($key, $validated['tenant_value']));
            $note .= ' + '.$tenant->name.' override';
        }

        AuditLog::create([
            'tenant_id' => $tenant->id,
            'user_id' => auth()->id(),
            'actor_name' => auth()->user()?->name ?? 'Super Admin',
            'action' => 'Updated feature flag',
            'target' => $key.' ('.$note.($locked ? ', locked' : '').')',
        ]);

        return back()->with('ok', Features::label($key).' updated — '.$note.'.');
    }

    /** Build the grouped matrix view model. */
    private function matrixData(Tenant $tenant): array
    {
        $modules = [];
        foreach (Features::MODULES as $key => [$label, $screens]) {
            $modules[] = $this->row($tenant, $key, $label, 'bool');
        }

        $tenantSettings = [];
        $platformSettings = [];
        foreach (Features::SETTINGS as $key => $meta) {
            $row = $this->row($tenant, $key, $meta['label'], $meta['type'], $meta['options'] ?? null);
            if (($meta['scope'] ?? 'tenant') === 'platform') {
                $platformSettings[] = $row;
            } else {
                $tenantSettings[] = $row;
            }
        }

        return [
            'company' => $tenant,
            'modules' => $modules,
            'tenantSettings' => $tenantSettings,
            'platformSettings' => $platformSettings,
        ];
    }

    /** One key's resolved state across all scopes. */
    private function row(Tenant $tenant, string $key, string $label, string $type, ?array $options = null): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'type' => $type,
            'options' => $options,
            'platformValue' => $this->features->platformValue($key),
            'locked' => $this->features->platformLocked($key),
            'resolved' => $this->features->value($tenant, $key),
        ];
    }

    /** Validation rule for a key's value, derived from its registry type. */
    private function ruleFor(string $key): string
    {
        $meta = Features::meta($key);
        if ($meta && ($meta['type'] ?? null) === 'enum') {
            return 'in:'.implode(',', array_keys($meta['options']));
        }

        if ($meta && ($meta['type'] ?? null) === 'number') {
            return 'numeric|min:'.($meta['min'] ?? 0).'|max:'.($meta['max'] ?? 1000000);
        }

        // Modules + bool settings: accept the standard truthy/falsy tokens.
        return 'in:0,1,true,false';
    }

    /** Coerce a validated string into the storable type for a key. */
    private function cast(string $key, mixed $value): mixed
    {
        $meta = Features::meta($key);
        if ($meta && ($meta['type'] ?? null) === 'enum') {
            return (string) $value;
        }

        if ($meta && ($meta['type'] ?? null) === 'number') {
            return (float) $value;
        }

        return Features::asBool($value);
    }
}
