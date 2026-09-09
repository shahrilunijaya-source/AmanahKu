<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * CR-22: one curated character-arc title, HR/director editable. `rule` is one of
 * `wrapped:build`'s five rule keys (firefighter/helper/closer/quiet/steady), checked
 * in that order to pick which bucket an employee's month falls into; the title shown
 * is picked among the active arcs of the matching rule.
 */
class WrappedArc extends Model
{
    use BelongsToTenant;

    public const RULES = ['firefighter', 'helper', 'closer', 'quiet', 'steady'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
