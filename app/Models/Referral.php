<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Referral extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['bonus_eligible' => 'boolean', 'decided_at' => 'datetime'];
    }

    /** The employee who made the referral. */
    public function referrer(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'referrer_employee_id');
    }

    /** The open role the candidate was referred to (optional). */
    public function jobRequisition(): BelongsTo
    {
        return $this->belongsTo(JobRequisition::class);
    }
}
