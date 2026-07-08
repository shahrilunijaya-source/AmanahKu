<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Candidate extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    /** The requisition this candidate is applying to. */
    public function requisition(): BelongsTo
    {
        return $this->belongsTo(JobRequisition::class, 'job_requisition_id');
    }
}
