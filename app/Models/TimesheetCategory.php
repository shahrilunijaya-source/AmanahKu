<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TimesheetCategory extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    /**
     * Category names meaningful to tag a Project with. Most timesheet categories
     * describe non-delivery work (HR, Leave, Marketing…) and never apply to a
     * project, so the project-tagging picker offers only this subset.
     *
     * @var list<string>
     */
    public const PROJECT_LINKABLE = ['Development', 'Maintenance', 'InHouse Project', 'Sales'];

    protected function casts(): array
    {
        return [
            'requires_project' => 'boolean',
            'is_active' => 'boolean',
            'sort' => 'integer',
        ];
    }

    public function entries(): HasMany
    {
        return $this->hasMany(TimesheetEntry::class, 'category_id');
    }

    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_timesheet_category');
    }

    public function scopeProjectLinkable(Builder $query): Builder
    {
        return $query->whereIn('name', self::PROJECT_LINKABLE);
    }
}
