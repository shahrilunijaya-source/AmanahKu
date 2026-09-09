<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * CR-31: one row per (employee, kind, day) an easter egg was actually shown —
 * the once-a-day-per-user gate. See App\Support\EasterEggBank::showOnce().
 */
class EasterEggView extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['shown_on' => 'date:Y-m-d'];
    }
}
