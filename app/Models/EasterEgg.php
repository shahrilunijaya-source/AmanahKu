<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * CR-31: one dashboard/board easter-egg line. HR curates a bank per tenant
 * (approved lines) on Company Settings. App\Support\EasterEggBank::pick()
 * picks a random approved line for the active kind; ::showOnce() adds the
 * once-a-day-per-user gate through easter_egg_views.
 *
 * @property Carbon|null $approved_at
 */
class EasterEgg extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['approved_at' => 'datetime'];
    }

    public function suggestedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'suggested_by');
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->whereNotNull('approved_at');
    }
}
