<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OffboardingCase extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'last_day' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** The resignation that spawned this case, if any (termination/EOC/retirement have none). */
    public function resignation(): BelongsTo
    {
        return $this->belongsTo(Resignation::class);
    }

    public function clearanceItems(): HasMany
    {
        return $this->hasMany(ClearanceItem::class)->orderBy('sort');
    }
}
