<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Experience tab · Language proficiency row. */
class EmployeeLanguage extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
