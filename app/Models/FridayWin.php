<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * CR-29 Friday Sign-Off: "my win this week", one line, tied to an employee
 * and (only when `shared` is true) shown under their name. Unlike
 * `friday_moods`/`friday_receipts` this is never anonymous — the CR says the
 * name is posted deliberately when the author opts in.
 *
 * @property int $id
 * @property Carbon $week_of
 * @property int $employee_id
 * @property string $text
 * @property bool $shared
 */
class FridayWin extends Model
{
    use BelongsToTenant;

    /** No created_at/updated_at columns — see the migration's anonymity note. */
    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['week_of' => 'date', 'shared' => 'boolean'];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
