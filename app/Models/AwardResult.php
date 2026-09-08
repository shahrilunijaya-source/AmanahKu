<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CR-14b: a thin Eloquent wrapper around `award_results`. S17 (`AwardsPublish`) writes
 * every row with `DB::table()` and still does — this model exists only so S18 code can
 * route-model-bind `/app/awards/{result}/...` and so the Director override (Global
 * Clause item 3) has a `Model` to hand `AuditLog::change()`. No new computation here.
 */
class AwardResult extends Model
{
    use BelongsToTenant;

    protected $table = 'award_results';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['month' => 'date', 'published_at' => 'datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
