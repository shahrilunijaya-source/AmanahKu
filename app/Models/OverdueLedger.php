<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * CR-17 / CR-14 rule 8: one row per (card, owner, calendar month) an overdue card was
 * held against. Written on reassign so a card that moves owners mid-month still counts
 * against whoever held it that month, even after it changes hands.
 *
 * @property int $work_item_id
 * @property int $employee_id
 * @property Carbon $month
 * @property int $days_overdue
 */
class OverdueLedger extends Model
{
    use BelongsToTenant;

    protected $table = 'overdue_ledger';

    protected $guarded = [];

    public function workItem(): BelongsTo
    {
        return $this->belongsTo(WorkItem::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
