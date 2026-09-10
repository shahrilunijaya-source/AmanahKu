<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $poll_id
 * @property string $label
 */
class PlotTwistOption extends Model
{
    protected $guarded = [];

    /** @return BelongsTo<PlotTwistPoll, $this> */
    public function poll(): BelongsTo
    {
        return $this->belongsTo(PlotTwistPoll::class, 'poll_id');
    }
}
