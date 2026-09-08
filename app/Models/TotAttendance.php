<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One row per person per TOT session: hadir/tidak hadir plus the absentee's reason. */
class TotAttendance extends Model
{
    use BelongsToTenant;

    protected $table = 'tot_attendance';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['present' => 'boolean'];
    }

    /** @return BelongsTo<TotSession, $this> */
    public function session(): BelongsTo
    {
        return $this->belongsTo(TotSession::class, 'session_id');
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
