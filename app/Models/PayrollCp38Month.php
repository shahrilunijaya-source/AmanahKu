<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * CP38 for one employee in one month ('YYYY-MM'), as HR types it into the Worksy-style
 * 12-month grid on Transaction → CP38. A month with no row takes nothing.
 */
class PayrollCp38Month extends Model
{
    use BelongsToTenant;

    /** @var list<string> */
    protected $fillable = ['employee_id', 'period', 'amount'];

    protected function casts(): array
    {
        return ['amount' => 'float'];
    }

    /** What a pay run for $period deducts from $employee as CP38. */
    public static function amountFor(Employee $employee, string $period): float
    {
        return round((float) self::where('tenant_id', $employee->tenant_id)
            ->where('employee_id', $employee->id)->where('period', $period)->value('amount'), 2);
    }
}
