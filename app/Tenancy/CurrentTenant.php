<?php

namespace App\Tenancy;

use App\Models\Tenant;

/**
 * Holds the active tenant for the current request.
 * Bound as a singleton; the tenant global scope reads its live state.
 */
class CurrentTenant
{
    protected ?Tenant $tenant = null;

    public function set(?Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function get(): ?Tenant
    {
        return $this->tenant;
    }

    public function id(): ?int
    {
        return $this->tenant?->id;
    }

    public function check(): bool
    {
        return $this->tenant !== null;
    }
}
