<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A confidential 1:1 chat request from an employee to HR. Intentionally identified
 * (a 1:1 must show who asked) — visible only to the requester and HR.
 */
class WellnessRequest extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['handled_at' => 'datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function handledBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'handled_by_id');
    }
}
