<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * CR-26 Side Quests: an optional, non-KPI challenge HR/director keep live (at
 * most 3 at a time). Route-model binding is not tenant-scoped (SubstituteBindings
 * runs before ResolveTenant — same trap as BigDeal/PlotTwist), so every controller
 * action re-checks tenant_id before touching a bound quest.
 */
class SideQuest extends Model
{
    use BelongsToTenant;

    public const MAX_LIVE = 3;

    protected $guarded = [];

    public function posts(): HasMany
    {
        return $this->hasMany(SideQuestPost::class, 'quest_id');
    }

    public function suggestedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'suggested_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }
}
