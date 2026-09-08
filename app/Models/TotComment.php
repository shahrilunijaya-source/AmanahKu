<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TotComment extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    /**
     * @return BelongsTo<TotSession, $this>
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(TotSession::class, 'session_id');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<TotSlot, $this>
     */
    public function slot(): BelongsTo
    {
        return $this->belongsTo(TotSlot::class, 'slot_id');
    }
}
