<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A Keputusan/Tindakan Susulan row: what was agreed, who owns it, and by when. `target_date`
 * null means "Bulan hadapan" (docs/build/contracts/dates.md Rule 4) — the effective due date
 * is computed at card-creation time from the session's own month, never guessed here.
 *
 * @property Carbon|null $target_date
 */
class TotAction extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['target_date' => 'date'];
    }

    /** @return BelongsTo<TotSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(TotSession::class, 'session_id');
    }

    /** @return BelongsTo<TotSlot, $this> */
    public function slot(): BelongsTo
    {
        return $this->belongsTo(TotSlot::class, 'slot_id');
    }

    /** @return BelongsTo<Employee, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'owner_employee_id');
    }

    /** @return BelongsTo<WorkItem, $this> */
    public function workItem(): BelongsTo
    {
        return $this->belongsTo(WorkItem::class, 'work_item_id');
    }

    /**
     * "PM and above" (roles contract) plus the tindakan's own owner, who may create their
     * own T.A.A. card without holding a management role.
     */
    public function canCreateCardBy(?string $role, ?Employee $employee): bool
    {
        $effective = $role !== null ? Permissions::effectiveRole($role) : null;

        return in_array($effective, ['manager', 'hr', 'management'], true)
            || ($employee !== null && $this->owner_employee_id === $employee->id);
    }
}
