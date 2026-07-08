<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeDocument extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    /** The employee this document belongs to (its owner). */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** The employee who uploaded the document (may differ from the owner). */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'uploaded_by_employee_id');
    }
}
