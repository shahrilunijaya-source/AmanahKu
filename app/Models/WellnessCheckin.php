<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A private wellness pulse entry. SENSITIVE: only ever queried for the employee's
 * own rows or as an anonymized aggregate — never exposed per-person to HR.
 */
class WellnessCheckin extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'mood' => 'integer',
            'stress' => 'integer',
            'checkin_date' => 'date',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
