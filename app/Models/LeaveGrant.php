<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One top-up of an HR-granted leave quota (Replacement). The days land on the matching
 * LeaveBalance; this row is the record of who granted them and what for.
 */
class LeaveGrant extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['days' => 'float'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'granted_by_id');
    }
}
