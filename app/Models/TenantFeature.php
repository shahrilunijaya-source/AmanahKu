<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * A single per-tenant feature override. Tenant-scoped via the global scope so a
 * tenant only ever sees and writes its own rows.
 */
class TenantFeature extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'key', 'value'];
}
