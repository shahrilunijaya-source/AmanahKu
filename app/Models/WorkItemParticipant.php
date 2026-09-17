<?php

declare(strict_types=1);

namespace App\Models;

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
}
