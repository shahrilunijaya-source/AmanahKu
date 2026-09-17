<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Calendar\TaggedCopies;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * The work_item_participant row as a model, so tagging and untagging fire events no
 * matter which of the many call sites writes the pivot (see Task 3 booted()).
 *
 * @property int $work_item_id
 * @property int $employee_id
 * @property string|null $role
 */
class WorkItemParticipant extends Pivot
{
    protected $table = 'work_item_participant';

    protected static function booted(): void
    {
        static::created(function (self $pivot): void {
            $item = WorkItem::withoutGlobalScopes()->find($pivot->work_item_id);
            if ($item) {
                TaggedCopies::pushOne($item, (int) $pivot->employee_id);
            }
        });

        static::deleted(function (self $pivot): void {
            TaggedCopies::removeFor((int) $pivot->work_item_id, (int) $pivot->employee_id);
        });
    }
}
