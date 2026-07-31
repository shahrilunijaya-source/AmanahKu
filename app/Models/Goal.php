<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Goal extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function keyResults(): HasMany
    {
        return $this->hasMany(KeyResult::class);
    }

    /**
     * Overall progress = average of key-result progress (0 when there are none).
     * Uses the loaded relation when available to avoid extra queries.
     */
    public function getProgressAttribute(): int
    {
        $results = $this->relationLoaded('keyResults')
            ? $this->keyResults
            : $this->keyResults()->get();

        return $results->isEmpty() ? 0 : (int) round($results->avg('progress'));
    }
}
