<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A company category (Stage 1 / 2 / 3) — a platform-level lookup that defines the
 * default feature package a super-admin assigns to a company. Not tenant-scoped
 * (global rows). The package itself is derived from the Features registry `stage`
 * tag (see App\Support\Features::modulesUpToStage), keeping a single source of
 * truth; this table stores the human-facing category metadata + level.
 */
class CompanyCategory extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['level' => 'integer'];
    }

    public function tenants(): HasMany
    {
        return $this->hasMany(Tenant::class);
    }
}
