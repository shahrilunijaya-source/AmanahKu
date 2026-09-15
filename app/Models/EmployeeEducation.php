<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Experience tab · Education row. */
class EmployeeEducation extends Model
{
    use BelongsToTenant;

    /** Str::plural treats "education" as uncountable, so name the table explicitly. */
    protected $table = 'employee_educations';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['from_year' => 'integer', 'to_year' => 'integer', 'cgpa' => 'float'];
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
