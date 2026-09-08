<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\AuditsChanges;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasAuditedFields;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model implements HasAuditedFields
{
    use AuditsChanges;
    use BelongsToTenant;

    protected $guarded = [];

    /**
     * The project master — the fields CR-06a versions and audits. Order matches the
     * "Details / Contract / People" grouping on the form. `code` (the old short badge)
     * and `project_code` (the new immutable integration key) are both here: both are
     * master data, just at different ages of the app.
     *
     * @var list<string>
     */
    public const MASTER_FIELDS = [
        'project_code', 'code', 'name', 'client', 'status', 'contract_value',
        'procurement_method', 'contractor', 'bond_value', 'bond_submitted_at',
        'loa_date', 'loa_ref', 'agreement_date', 'agreement_ref',
        'contract_start', 'contract_end', 'drive_link', 'pm_id', 'pe_id',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort' => 'integer',
            'contract_value' => 'decimal:2',
            'bond_value' => 'decimal:2',
            'bond_submitted_at' => 'date:Y-m-d',
            'loa_date' => 'date:Y-m-d',
            'agreement_date' => 'date:Y-m-d',
            'contract_start' => 'date:Y-m-d',
            'contract_end' => 'date:Y-m-d',
            'closed_at' => 'datetime',
        ];
    }

    /** Fields the Global Clause requires an audit entry for on change (AuditsChanges). */
    public function audited(): array
    {
        return self::MASTER_FIELDS;
    }

    /**
     * Timesheet categories this project falls under (e.g. Development, Maintenance).
     * This screen is the source of truth: a board card booked to the project is offered
     * exactly these, and inherits the answer outright when there is only one.
     *
     * @return BelongsToMany<TimesheetCategory, $this>
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(TimesheetCategory::class, 'project_timesheet_category');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(TimesheetEntry::class);
    }

    /** Board cards that name this project. Counted on the Projects register. */
    public function workItems(): HasMany
    {
        return $this->hasMany(WorkItem::class, 'project_id');
    }

    /** @return HasMany<ProjectVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(ProjectVersion::class)->orderBy('version_no');
    }

    /** @return HasMany<ProjectVariation, $this> */
    public function variations(): HasMany
    {
        return $this->hasMany(ProjectVariation::class)->latest('id');
    }

    /**
     * CR-06b §E5: shown on the register and to Track as `awaiting_approval`. Counts
     * off the already-loaded `variations` relation when present (the register eager
     * loads it for every row) rather than firing a fresh query per project.
     */
    public function pendingVariationsCount(): int
    {
        if ($this->relationLoaded('variations')) {
            return $this->variations->where('status', 'pending')->count();
        }

        return $this->variations()->reorder()->where('status', 'pending')->count();
    }

    /** @return BelongsTo<Employee, $this> */
    public function pm(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'pm_id');
    }

    /** @return BelongsTo<Employee, $this> */
    public function pe(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'pe_id');
    }

    /** @return BelongsTo<Employee, $this> */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'closed_by_id');
    }

    public function currentVersion(): ?ProjectVersion
    {
        // reorder(): versions() carries its own default ascending order for display
        // lists, which a plain orderByDesc() would only ever be a tiebreaker after —
        // SQL sorts by the first ORDER BY column first, so the ascending clause would
        // silently win and hand back the OLDEST version instead of the newest.
        return $this->versions()->reorder('version_no', 'desc')->first();
    }

    /**
     * The version in force on a given date — the latest version whose effective_date is
     * on or before it, ties (same effective_date) broken by the higher version number.
     * Null when the date predates the project's first version. Track reports read this,
     * never the latest version, so a re-run of an old report reproduces old figures.
     */
    public function versionEffectiveOn(string|CarbonInterface $date): ?ProjectVersion
    {
        $date = $date instanceof CarbonInterface ? $date->toDateString() : $date;

        return $this->versions()
            ->reorder()
            ->where('effective_date', '<=', $date)
            ->orderByDesc('effective_date')
            ->orderByDesc('version_no')
            ->first();
    }

    /**
     * The master fields as they stand right now, in the same shape a version's
     * `snapshot` column stores them: decimals as 2dp strings, dates as Y-m-d — the
     * cast serialization Eloquent already applies for JSON output, so this is exactly
     * what the project would look like if a version were cut this instant.
     *
     * @return array<string, mixed>
     */
    public function masterSnapshot(): array
    {
        return array_intersect_key($this->attributesToArray(), array_flip(self::MASTER_FIELDS));
    }

    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }
}
