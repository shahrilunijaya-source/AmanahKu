<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CR-22: one month's Wrapped for one employee (`employee_id` set) or for the whole
 * tenant (`employee_id` null — the company story shown on the dashboard). `cards`
 * holds numeric stats only, never comment/description text (see `Awards::
 * creditableCards()` and `wrapped:build`). Reactions (`wrapped_reactions`) stay plain
 * `DB::table()` access from the controller, same split as `victory_bell_reactions`.
 */
class WrappedStory extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'month' => 'date',
            'cards' => 'array',
            'shared_at' => 'datetime',
            'built_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
