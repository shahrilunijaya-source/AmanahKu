<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Values HR typed over on one LHDN staff form (CP21, CP22, CP22A, PCB II) for one
 * person and year. `fields` maps a field key from StaffFormData to the typed value.
 *
 * @property array<string, string> $fields
 */
class PayrollFormOverride extends Model
{
    use BelongsToTenant;

    /** @var list<string> */
    protected $fillable = ['employee_id', 'form', 'year', 'fields'];

    protected function casts(): array
    {
        return ['fields' => 'array', 'year' => 'integer'];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
