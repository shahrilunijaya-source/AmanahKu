<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Resignation extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'last_working_date' => 'date',
            'notice_days' => 'integer',
            'acknowledged_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** The confidential exit interview — populated by HR/management only. */
    public function exitInterview(): HasOne
    {
        return $this->hasOne(ExitInterview::class);
    }

    /** The exit-clearance case opened for this resignation, if any. */
    public function offboardingCase(): HasOne
    {
        return $this->hasOne(OffboardingCase::class);
    }
}
