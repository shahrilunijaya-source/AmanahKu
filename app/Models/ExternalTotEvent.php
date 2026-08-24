<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $event_date
 */
class ExternalTotEvent extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['event_date' => 'date'];
    }

    /** @return BelongsTo<Employee, $this> */
    public function poster(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'posted_by');
    }
}
