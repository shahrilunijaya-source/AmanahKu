<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A tenant-scoped employment type (Full-time, Contract, Part-time, Intern, …).
 *
 * `clock_exempt` marks a type whose staff keep their own hours (Freelance): nobody chases
 * them to clock in, and a punch they choose to make is never judged late, early or short.
 */
class EmploymentType extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['clock_exempt' => 'boolean'];
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }
}
