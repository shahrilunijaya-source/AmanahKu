<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExitInterview extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'would_recommend' => 'boolean',
            'ratings' => 'array',
            'conducted_at' => 'datetime',
        ];
    }

    public function resignation(): BelongsTo
    {
        return $this->belongsTo(Resignation::class);
    }
}
