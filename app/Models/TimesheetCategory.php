<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Services\FeatureManager;
use App\Tenancy\CurrentTenant;
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
     * Rows nobody picks by hand: LockedDays generates them from approved leave and the
     * public-holiday calendar (matched by name — see LockedDays::CATEGORY_NAME). While
     * the leave module is on they are filled in for the staffer, so offering them in a
     * picker only ever produces a second, unapproved copy of a day HR already decided.
     *
     * @var list<string>
     */
    public const GENERATED = ['On Leave', 'Public Holiday'];

    /**
     * The GENERATED names to hide from a picker right now — none when the leave module
     * is off, because such a tenant has no approved-leave source and needs to log leave
     * some other way. Request context only: it reads the current tenant's feature flags,
     * which observers and console commands deliberately run without.
     *
     * @return list<string>
     */
    public static function generatedNames(): array
    {
        return app(FeatureManager::class)->enabled(app(CurrentTenant::class)->get(), 'module.leave')
            ? self::GENERATED
            : [];
    }

    /**
     * What a new company starts with: the four effort types the director costs work
     * against, Others for everything not billed to a project, and the two rows
     * LockedDays files approved leave and public holidays under (matched by name —
     * see LockedDays::CATEGORY_NAME — and hidden from the staffer's own picker while
     * the leave module is on, so nobody logs leave HR never approved).
     *
     * A company with no categories has no way to cost anything at all now that the
     * capture screen has no category picker of its own: its rows come from board
     * cards, and a card with nothing behind it is held back rather than saved.
     *
     * @var list<array{0:string, 1:string, 2:bool}> name, name_ms, requires_project
     */
    public const DEFAULTS = [
        ['Development', 'Pembangunan', true],
        ['Maintenance', 'Penyelenggaraan', true],
        ['InHouse Project', 'Projek Dalaman', true],
        ['Sales', 'Jualan', true],
        ['Others', 'Lain-lain', false],
        ['Public Holiday', 'Cuti Umum', false],
        ['On Leave', 'Bercuti', false],
    ];

    /**
     * Give a tenant the default categories. Idempotent by name, so it is safe on a
     * company that already has some of them — an existing row is left exactly as the
     * company edited it.
     */
    public static function seedFor(Tenant $tenant): void
    {
        $existing = self::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->pluck('name')
            ->all();

        $sort = (int) self::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->max('sort');

        foreach (self::DEFAULTS as [$name, $nameMs, $requiresProject]) {
            if (in_array($name, $existing, true)) {
                continue;
            }

            self::create([
                'tenant_id' => $tenant->id,
                'name' => $name,
                'name_ms' => $nameMs,
                'requires_project' => $requiresProject,
                'sort' => $sort++,
                'is_active' => true,
            ]);
        }
    }

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
