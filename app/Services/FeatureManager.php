<?php

namespace App\Services;

use App\Models\PlatformFeature;
use App\Models\Tenant;
use App\Models\TenantFeature;
use App\Support\Features;

/**
 * Resolves admin-toggleable feature values. Single resolution authority used by
 * the nav, screen gate, login policy, payroll, registration and the toggle UIs.
 *
 * Resolution: platform-locked → platform value; else tenant override ?? platform
 * value ?? registry default. Reads are memoised per request.
 */
class FeatureManager
{
    /** @var array<string, array{value:string, locked:bool}>|null */
    private ?array $platform = null;

    /** @var array<int, array<string, string>> */
    private array $tenantCache = [];

    /** Resolve the raw value of a feature for a tenant. */
    public function value(?Tenant $tenant, string $key): mixed
    {
        $platform = $this->platform();

        if (isset($platform[$key]) && $platform[$key]['locked']) {
            return $platform[$key]['value'];
        }

        if ($tenant) {
            $overrides = $this->tenantOverrides($tenant);
            if (array_key_exists($key, $overrides)) {
                return $overrides[$key];
            }
        }

        return $this->platformValue($key);
    }

    /** Boolean view of a feature. */
    public function enabled(?Tenant $tenant, string $key): bool
    {
        return Features::asBool($this->value($tenant, $key));
    }

    /** Is a screen reachable for this tenant (its gating module enabled, or core)? */
    public function screenAllowed(?Tenant $tenant, string $screen): bool
    {
        $module = Features::moduleForScreen($screen);

        return $module === null || $this->enabled($tenant, $module);
    }

    /** Platform default for a key (super-admin value, else registry default). */
    public function platformValue(string $key): mixed
    {
        $platform = $this->platform();

        return $platform[$key]['value'] ?? Features::default($key);
    }

    public function platformLocked(string $key): bool
    {
        return $this->platform()[$key]['locked'] ?? false;
    }

    /** Every key resolved for a tenant — for the tenant settings UI + nav. */
    public function allForTenant(?Tenant $tenant): array
    {
        $out = [];
        foreach (array_keys(Features::defaults()) as $key) {
            $out[$key] = $this->value($tenant, $key);
        }

        return $out;
    }

    // ── Writes ────────────────────────────────────────────────────────────

    public function setPlatform(string $key, mixed $value, bool $locked): void
    {
        PlatformFeature::updateOrCreate(
            ['key' => $key],
            ['value' => $this->store($value), 'locked' => $locked],
        );
        $this->platform = null;
    }

    public function setTenant(Tenant $tenant, string $key, mixed $value): void
    {
        TenantFeature::updateOrCreate(
            ['tenant_id' => $tenant->id, 'key' => $key],
            ['value' => $this->store($value)],
        );
        unset($this->tenantCache[$tenant->id]);
    }

    /**
     * Seed a tenant's module entitlements from a company-category stage level (1/2/3).
     * Cumulative: a module is enabled when its registry stage ≤ $level, disabled
     * otherwise. Writes an explicit tenant_feature row per module so the resolved
     * entitlement — not the category — is the source of truth thereafter (task §2).
     * Non-module settings (security, payroll, AI assistant) are intentionally left
     * untouched. Locked keys still resolve to the platform value regardless.
     */
    public function applyCategoryPackage(Tenant $tenant, int $level): void
    {
        foreach (Features::MODULES as $key => $def) {
            $this->setTenant($tenant, $key, ($def[2] ?? 1) <= $level);
        }
    }

    // ── Internals ─────────────────────────────────────────────────────────

    private function store(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string) $value;
    }

    /** @return array<string, array{value:string, locked:bool}> */
    private function platform(): array
    {
        return $this->platform ??= PlatformFeature::all()
            ->mapWithKeys(fn ($f) => [$f->key => ['value' => $f->value, 'locked' => $f->locked]])
            ->all();
    }

    /** @return array<string, string> */
    private function tenantOverrides(Tenant $tenant): array
    {
        return $this->tenantCache[$tenant->id] ??= TenantFeature::query()
            ->where('tenant_id', $tenant->id)
            ->pluck('value', 'key')
            ->all();
    }
}
