<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A contract variation (VO) — CR-06b §E4, E5. `changes` is {field: {old, new}} over
 * contract_value / contract_start / contract_end / client only; `delta` is the
 * contract_value movement when that field is touched, null otherwise. Raised pending;
 * decided (approved/rejected) by the management tier. Approval writes the next
 * ProjectVersion and points `version_id` at it; rejection never touches the project.
 */
class ProjectVariation extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'variation_date' => 'date:Y-m-d',
            'decided_at' => 'datetime',
            'delta' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function raisedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'raised_by_id');
    }

    /** @return BelongsTo<User, $this> */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_id');
    }

    /** @return BelongsTo<ProjectVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(ProjectVersion::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
