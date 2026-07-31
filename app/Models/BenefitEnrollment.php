<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BenefitEnrollment extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'dependents' => 'integer',
            'enrolled_at' => 'date',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(BenefitPlan::class, 'benefit_plan_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
