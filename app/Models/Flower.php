<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\FlowerFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A "Caught Being Brilliant" flower (CR-23): one colleague recognising another,
 * capped at 3 given per person per month, no approval needed. Hideable by HR
 * only (moderation, not a per-user delete).
 *
 * @property Carbon $hidden_at
 */
class Flower extends Model
{
    /** @use HasFactory<FlowerFactory> */
    use BelongsToTenant, HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['hidden_at' => 'datetime'];
    }

    public function giver(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'giver_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'recipient_id');
    }

    public function hiddenBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'hidden_by_id');
    }

    /** Not hidden by HR — every read site (Wall, dashboard widget) filters through this. */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->whereNull('hidden_at');
    }
}
