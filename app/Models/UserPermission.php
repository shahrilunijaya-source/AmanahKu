<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * A single per-user permission override within a tenant. granted=true adds a
 * permission the role lacks; granted=false removes one the role grants.
 */
class UserPermission extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['granted' => 'boolean'];
    }
}
