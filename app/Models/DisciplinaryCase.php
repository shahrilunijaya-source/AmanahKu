<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DisciplinaryCase extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    /** The employee the case concerns (the subject). */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** The privileged staff member who opened the case (nullable). */
    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'opened_by_employee_id');
    }
}
