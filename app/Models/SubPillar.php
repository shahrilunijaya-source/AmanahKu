<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A kind of work staff can book time against — Management, Meeting, Technical.
 * Tenant-wide and shared by every project: replaced the per-project
 * ProjectSubPillar, which stored the same three names once per project.
 */
class SubPillar extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    /**
     * Mirrors the migration's column defaults so a freshly-created, unrefreshed
     * model reads the same values a re-fetched row would (Eloquent does not
     * re-read DB-side defaults after an insert).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
        'sort' => 0,
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort' => 'integer',
        ];
    }

    public function entries(): HasMany
    {
        return $this->hasMany(TimesheetEntry::class, 'sub_pillar_id');
    }
}
