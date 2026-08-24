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

    /**
     * One colour per project-linkable category, so a category reads the same
     * everywhere it appears — the dot in the timesheet picker and the pill on
     * the Projects register. Hexes are dark enough to sit as text on their own
     * tinted pill, which the raw fill vars (--amber, --success) are not.
     *
     * @var array<string, string>
     */
    public const COLOURS = [
        'Development' => 'var(--info)',
        'Maintenance' => 'var(--amber-ink)',
        'InHouse Project' => 'var(--success-ink)',
        'Sales' => '#8a4bdb',
    ];

    /**
     * This category's colour. Named categories get their own; everything else
     * falls into a group, so a tenant that adds "Client Marketing" still gets a
     * sensible colour instead of the grey fallback.
     */
    public function colour(): string
    {
        if (isset(self::COLOURS[$this->name])) {
            return self::COLOURS[$this->name];
        }
        // The -ink pairs, not --success / --amber: this value is used as pill text as
        // well as a dot, and the raw fill tokens measure under 4.5:1 on white (see
        // docs/DESIGN.md, The Text-Safe Tone Rule). The docblock above already promised
        // every value here is dark enough to be read as text; these two were not.
        if (preg_match('/leave|holiday/i', $this->name)) {
            return 'var(--success-ink)';
        }
        if (preg_match('/marketing/i', $this->name)) {
            return 'var(--amber-ink)';
        }
        if (preg_match('/account|admin/i', $this->name)) {
            return 'var(--error)';
        }

        return $this->requires_project ? 'var(--info)' : 'var(--muted-soft)';
    }

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
