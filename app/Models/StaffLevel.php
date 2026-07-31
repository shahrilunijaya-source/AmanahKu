<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A tenant-scoped staff grade/level (e.g. L1–L6). Lookup that the legacy
 * Employee.level free-text string is migrating towards.
 */
class StaffLevel extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['rank' => 'integer'];
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }
}
