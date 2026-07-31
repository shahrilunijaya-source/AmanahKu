<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProbationReview extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'decided_at' => 'datetime',
            'length_days' => 'integer',
        ];
    }

    /** The new hire on probation. */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** The privileged staff member who recorded the final decision (nullable). */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'decided_by_id');
    }

    /** Scheduled / ad-hoc check-ins, oldest first. */
    public function checkins(): HasMany
    {
        return $this->hasMany(ProbationCheckin::class)->orderBy('checkin_date');
    }
}
