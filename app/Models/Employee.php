<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Collection;

class Employee extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    // The assigned Position band is the single source of truth for a person's job
    // title — always loaded so the `position` accessor below never lazy-loads.
    protected $with = ['positionBand'];

    /**
     * Job title — always derived from the assigned Position band, never the legacy
     * free-text column. Null when no band is assigned, so stale seed data never shows.
     */
    protected function position(): Attribute
    {
        return Attribute::make(get: fn () => $this->positionBand?->title);
    }

    /**
     * Live workload tiers, driven by a person's OPEN (not-done) work-item count — never a stored
     * column. The `workload` / `workloadLabel` accessors below are the single source of truth;
     * the same-named DB columns are legacy seed data and are no longer read anywhere.
     */
    public const WORKLOAD_BUSY_FROM = 4;

    public const WORKLOAD_OVERLOADED_FROM = 7;

    /**
     * [colour, label] for a given open work-item count. On-leave staff read as grey regardless of
     * load — they carry no active work today. Shared by the accessors and by WorkforceInsights so
     * the dashboard, directory tiles, workload screen and recs can never disagree.
     *
     * @return array{0: string, 1: string}
     */
    public static function workloadFor(int $openItems, bool $onLeave): array
    {
        return match (true) {
            $onLeave => ['grey', 'On leave'],
            $openItems >= self::WORKLOAD_OVERLOADED_FROM => ['red', 'Overloaded'],
            $openItems >= self::WORKLOAD_BUSY_FROM => ['amber', 'Near capacity'],
            default => ['green', 'Healthy'],
        };
    }

    /**
     * This person's open (status != done) work-item count. Prefers an eager `open_items_count`
     * (add `withCount(['workItems as open_items_count' => fn ($q) => $q->where('status', '!=',
     * 'done')])` when listing many staff) to avoid an N+1, then a loaded relation, and only falls
     * back to a COUNT query as a last resort.
     */
    public function openWorkItemCount(): int
    {
        if (array_key_exists('open_items_count', $this->attributes)) {
            return (int) $this->attributes['open_items_count'];
        }

        if ($this->relationLoaded('workItems')) {
            return $this->workItems->where('status', '!=', 'done')->count();
        }

        return $this->workItems()->where('status', '!=', 'done')->count();
    }

    /** Live workload colour (green/amber/red/grey). Overrides the legacy `workload` column. */
    protected function workload(): Attribute
    {
        return Attribute::make(
            get: fn () => self::workloadFor($this->openWorkItemCount(), $this->status === 'on_leave')[0],
        );
    }

    /** Live workload label. Overrides the legacy `workload_label` column. */
    protected function workloadLabel(): Attribute
    {
        return Attribute::make(
            get: fn () => self::workloadFor($this->openWorkItemCount(), $this->status === 'on_leave')[1],
        );
    }

    protected function casts(): array
    {
        return [
            'joined_at' => 'date',
            'date_of_birth' => 'date',
            // Personal identity captured by the first-login wizard; encrypted at rest
            // like salary_structures.nric (migration 2026_06_24_000022).
            'nric' => 'encrypted',
            'skills' => 'array',
            'interests' => 'array',
            'personality' => 'array',
            'leave_balance' => 'float',
            'salary' => 'decimal:2',
            'home_latitude' => 'decimal:7',
            'home_longitude' => 'decimal:7',
            'home_locked_at' => 'datetime',
            'hybrid_office_days' => 'array',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * Active (not archived) staff only. Apply to directory listings, headcount
     * counts, payroll payee generation and assignment/recipient pickers — anywhere
     * the user is choosing or counting CURRENT staff. Historical references (a
     * payslip's, claim's or attendance record's owner) deliberately do NOT use this,
     * so archived people still resolve their name everywhere they are referenced.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    /**
     * Archived staff only — the inverse of scopeActive(). Backs the directory's
     * HR/management "Archived" recovery view, where they can be restored.
     */
    public function scopeArchived(Builder $query): Builder
    {
        return $query->whereNotNull('archived_at');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * PERKESO contribution category as of $asOf: 2 if the employee is 60 or older
     * (SOCSO Employment-Injury only, no EIS), otherwise 1. Defaults to 1 when DOB is
     * unknown — the payroll run flags missing DOBs separately so this is never silent.
     */
    public function statutoryCategory(?CarbonInterface $asOf = null): int
    {
        if ($this->date_of_birth === null) {
            return 1;
        }

        $asOf ??= now();

        return $this->date_of_birth->diffInYears($asOf) >= 60 ? 2 : 1;
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function staffLevel(): BelongsTo
    {
        return $this->belongsTo(StaffLevel::class);
    }

    public function employmentType(): BelongsTo
    {
        return $this->belongsTo(EmploymentType::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** Client site for resident engineers (work_arrangement = client). */
    public function workSite(): BelongsTo
    {
        return $this->belongsTo(WorkSite::class);
    }

    public function reportsTo(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'reports_to_id');
    }

    /**
     * Additional (dotted-line) managers on top of the primary reports_to link. Any of
     * them may verify this person's leave/claim/overtime requests ("either manager
     * verifies"). Display-only in the tree, which still nests under reports_to_id.
     */
    public function additionalManagers(): BelongsToMany
    {
        // Archived managers are excluded everywhere this relation is read (verifierIds,
        // verifiers, org-chart display, DataScope) so a detached person is never a live
        // verifier or a rendered "also reports to". The pivot row survives for restore.
        return $this->belongsToMany(Employee::class, 'employee_manager', 'employee_id', 'manager_id')
            ->whereNull('employees.archived_at');
    }

    /**
     * Every id that may verify this person's requests: the primary superior plus any
     * additional managers, de-duplicated and null-free. The single source of truth for
     * "who is a manager of this requester", used by both the guard and the queue.
     *
     * @return list<int>
     */
    public function verifierIds(): array
    {
        $ids = collect([$this->reports_to_id])
            ->merge($this->additionalManagers->modelKeys())
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        // Drop any archived manager — an archived primary superior (reports_to_id still set
        // but the person offboarded) must never be a live verify target. additionalManagers
        // is already active-scoped; this also filters the primary link.
        return Employee::active()->whereKey($ids->all())->pluck('id')->all();
    }

    /**
     * The manager Employee records (primary + additional) to notify when a request needs
     * verification. Deduplicated by id so a person listed twice is pinged once.
     *
     * @return Collection<int, Employee>
     */
    public function verifiers(): Collection
    {
        return collect([$this->reportsTo])
            ->merge($this->additionalManagers)
            ->filter(fn ($m) => $m && ! $m->isArchived())
            ->unique('id')
            ->values();
    }

    /** Login account, when this directory record has been provisioned one. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Position (rank band) that drives this employee's timesheet costing rate.
     * Named positionBand(), not position(), so it never shadows the free-text
     * `position` job-title attribute on magic property access.
     */
    public function positionBand(): BelongsTo
    {
        // Explicit FK: the relation is named positionBand (to avoid shadowing the
        // `position` job-title attribute), so Eloquent would otherwise infer
        // `position_band_id` instead of the real `position_id` column.
        return $this->belongsTo(Position::class, 'position_id');
    }

    public function workItems(): HasMany
    {
        return $this->hasMany(WorkItem::class);
    }

    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    public function leaveBalances(): HasMany
    {
        return $this->hasMany(LeaveBalance::class);
    }

    /**
     * Headline "leave balance" = the Annual leave balance only. The profile and dashboard
     * cards used to sum every type (Annual + Medical + Hospitalization…), which conflates
     * paid annual leave with medical/statutory entitlements into one misleading number.
     * Annual is the canonical primary type (see LeaveSetupController standard set); matched
     * by name so a renamed/missing type just yields 0 rather than a wrong total.
     */
    public function annualLeaveBalance(): float
    {
        return (float) ($this->leaveBalances
            ->first(fn (LeaveBalance $b) => $b->leaveType && strcasecmp($b->leaveType->name, 'Annual') === 0)
            ?->balance ?? 0);
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function kpiItems(): HasMany
    {
        return $this->hasMany(KpiItem::class);
    }

    public function careerTimeline(): HasMany
    {
        return $this->hasMany(CareerTimelineEntry::class)->orderBy('sort');
    }

    public function onboardingProfile(): HasOne
    {
        return $this->hasOne(OnboardingProfile::class);
    }

    public function profileTestResult(): HasOne
    {
        return $this->hasOne(ProfileTestResult::class);
    }

    /** Uploaded documents (contracts, certificates, IDs) owned by this employee. */
    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class);
    }

    public function claims(): HasMany
    {
        return $this->hasMany(Claim::class);
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }

    public function trainingRecords(): HasMany
    {
        return $this->hasMany(TrainingRecord::class);
    }

    public function performanceReviews(): HasMany
    {
        return $this->hasMany(PerformanceReview::class);
    }

    public function achievements(): HasMany
    {
        return $this->hasMany(Achievement::class);
    }

    public function salaryStructure(): HasOne
    {
        return $this->hasOne(SalaryStructure::class);
    }

    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }
}
