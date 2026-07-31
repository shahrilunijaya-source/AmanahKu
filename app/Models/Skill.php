<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Skill extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    public function employeeSkills(): HasMany
    {
        return $this->hasMany(EmployeeSkill::class);
    }
}
