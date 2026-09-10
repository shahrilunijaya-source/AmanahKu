<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BirthdayWishReaction extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    public function wish(): BelongsTo
    {
        return $this->belongsTo(BirthdayWish::class, 'wish_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
