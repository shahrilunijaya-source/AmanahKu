<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Experience tab · Certificate row. */
class EmployeeCertificate extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['awarded_on' => 'date', 'expires_on' => 'date'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(EmployeeDocument::class);
    }
}
