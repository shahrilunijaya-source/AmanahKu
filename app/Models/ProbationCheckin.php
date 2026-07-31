<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProbationCheckin extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'checkin_date' => 'date',
            'rating' => 'integer',
        ];
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(ProbationReview::class, 'probation_review_id');
    }
}
