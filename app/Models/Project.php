<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort' => 'integer',
        ];
    }

    public function subPillars(): HasMany
    {
        return $this->hasMany(ProjectSubPillar::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(TimesheetEntry::class);
    }
}
