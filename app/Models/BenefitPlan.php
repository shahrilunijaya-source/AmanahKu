<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BenefitPlan extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'monthly_cost' => 'decimal:2',
            'active' => 'boolean',
        ];
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(BenefitEnrollment::class);
    }
}
