<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CR-26: one self-declared completion of a Side Quest (a one-liner, a photo, or
 * both). Reactions (`side_quest_reactions`) stay plain `DB::table()` access from
 * the controller, same split as `big_deal_reactions`. Route-model binding is not
 * tenant-scoped — see SideQuest's docblock — so every action re-checks tenant_id.
 */
class SideQuestPost extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    public function quest(): BelongsTo
    {
        return $this->belongsTo(SideQuest::class, 'quest_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
