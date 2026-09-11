<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CR-21: one row per person who has upvoted an OfficeRequest (the requester is the first
 * vote, cast at raise time). No audit trail — the Global Clause marks upvote optional.
 */
class OfficeRequestVote extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    /** @return BelongsTo<OfficeRequest, $this> */
    public function officeRequest(): BelongsTo
    {
        return $this->belongsTo(OfficeRequest::class);
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
