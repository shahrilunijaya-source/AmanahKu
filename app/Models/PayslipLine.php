<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One itemised amount on a payslip, snapshotting its catalogue item's name at write time
 * so a later rename never rewrites an already-issued payslip's history. Additive to the
 * existing lumped Payslip columns, not a replacement of them.
 */
class PayslipLine extends Model
{
    use BelongsToTenant;

    /** @var list<string> */
    protected $fillable = [
        'payslip_id',
        'payroll_item_id',
        'name',
        'type',
        'amount',
        'quantity',
        'source',
        'remark',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'quantity' => 'float',
            'sort_order' => 'integer',
        ];
    }

    public function payslip(): BelongsTo
    {
        return $this->belongsTo(Payslip::class);
    }

    public function payrollItem(): BelongsTo
    {
        return $this->belongsTo(PayrollItem::class);
    }
}
