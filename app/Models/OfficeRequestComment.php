<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** CR-21 scope 2: a plain threadless comment on an OfficeRequest — body + who wrote it. */
class OfficeRequestComment extends Model
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
