<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** CR-24: one photo on a Big Deal, up to three per deal, stored on the `local` disk. */
class BigDealPhoto extends Model
{
    protected $guarded = [];

    public function bigDeal(): BelongsTo
    {
        return $this->belongsTo(BigDeal::class);
    }
}
