<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JobRequisition extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['openings' => 'integer'];
    }

    /** The department the requisition is opened for (optional). */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** Candidates in this requisition's pipeline. */
    public function candidates(): HasMany
    {
        return $this->hasMany(Candidate::class);
    }
}
