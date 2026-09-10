<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One row per effective change to a project's master fields (CR-06a §E2, E3).
 * `snapshot` is the full master-field state as of this version; `changes` is
 * {field: {old, new}} for what moved since the previous version (null on the
 * very first version). Append-only in spirit — nothing in this CR updates or
 * deletes a version row.
 *
 * @property Carbon $effective_date
 */
class ProjectVersion extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    /** Versions are written once and never touched again — no updated_at to track. */
    const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'changes' => 'array',
            'effective_date' => 'date:Y-m-d',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
