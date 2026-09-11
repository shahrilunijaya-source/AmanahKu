<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A private entry on one day of the owner's dashboard calendar: either a note
 * (title, optional time, text) or a board card pinned there (work_item_id set).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $employee_id
 * @property int|null $work_item_id
 * @property Carbon $date
 * @property string|null $title
 * @property string|null $starts_at
 * @property string|null $ends_at
 * @property string|null $body
 */
class CalendarNote extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    public function workItem(): BelongsTo
    {
        return $this->belongsTo(WorkItem::class);
    }

    public function isPin(): bool
    {
        return $this->work_item_id !== null;
    }

    /** "09:00 – 10:30", "09:00", or null when the note has no time. */
    public function timeLabel(): ?string
    {
        if ($this->starts_at === null) {
            return null;
        }

        $from = substr($this->starts_at, 0, 5);

        return $this->ends_at === null ? $from : $from.' – '.substr($this->ends_at, 0, 5);
    }
}
