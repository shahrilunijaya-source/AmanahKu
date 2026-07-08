<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A tenant-scoped employment type (Full-time, Contract, Part-time, Intern, …).
 */
class EmploymentType extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }
}
