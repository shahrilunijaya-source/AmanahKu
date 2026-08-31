<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * `due_at` has a `date` cast, so it reads back as a Carbon instance rather than
 * the string the schema reports.
 *
 * @property Carbon|null $due_at
 */
class WorkItem extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    /**
     * Fixed kanban label palette: slug => [display name, chip color]. Cards store
     * an array of these slugs in the `labels` JSON column. Pruned from six to
     * three in the T.A.A. board redesign (Stage 4, 2026-07-29): `urgent`,
     * `waiting` and `review` each duplicated a field the card already carried
     * (priority, blocked, status) and could contradict it, so they were folded
     * or removed by database/migrations/*_migrate_work_item_labels.php. This
     * palette is passed server-side into the Alpine component as a prop
     * (`resources/js/work-board.js`), so there is no JS twin to keep in sync.
     */
    public const LABELS = [
        'blocked' => ['Blocked', '#f76808'],
        'client' => ['Client', '#8a4bdb'],
        'internal' => ['Internal', '#5a6b7b'],
    ];

    protected function casts(): array
    {
        return ['due_at' => 'date', 'assigned_at' => 'datetime', 'archived_at' => 'datetime', 'done_at' => 'datetime', 'labels' => 'array', 'links' => 'array'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * The project this card is planned under. Optional. Named projectRef (not
     * project) to match TimesheetEntry and stay clear of any future `project`
     * column that would shadow the relation.
     *
     * @return BelongsTo<Project, $this>
     */
    public function projectRef(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    /**
     * The effort type this card's hours are costed as once they reach a timesheet.
     *
     * @return BelongsTo<TimesheetCategory, $this>
     */
    public function timesheetCategory(): BelongsTo
    {
        return $this->belongsTo(TimesheetCategory::class);
    }

    /**
     * The categories this card may be costed as: every active one a person may pick.
     * The card owns this answer now, so the list is not narrowed by the card's project —
     * the dependency runs the other way, and it is the project picker that a chosen
     * category narrows (see projectOptions()).
     *
     * @return Collection<int, TimesheetCategory>
     */
    public function timesheetCategoryOptions(): Collection
    {
        return TimesheetCategory::where('is_active', true)
            ->whereNotIn('name', TimesheetCategory::generatedNames())
            ->orderBy('sort')->orderBy('name')->get();
    }

    /**
     * The projects this card may be booked to, given the category it carries. A category
     * that needs no project (HR and Admin, Charity, Others) offers none at all — the
     * question does not arise. A delivery category offers the projects the project screen
     * tagged with it.
     *
     * A project tagged with nothing at all is offered under every category: it has said
     * nothing, not "no category fits", and dropping it would make existing projects
     * disappear the moment tagging starts. That is the same rule the timesheet's own
     * project picker applies (TimesheetController::projectOptions()), read from the same
     * pivot, so the prefilled row and the card's drawer can never disagree.
     *
     * The tagged/untagged test is on the PROJECT, never on the category: "a category with
     * no tagged projects offers all of them" would read the pivot from the wrong end and
     * quietly switch the pairing guard off for that category — every project would pair
     * with it, including ones the guard had just unbooked. A category nobody has tagged a
     * project with offers only the untagged projects, and the fix for that is to tag one
     * on the Projects screen.
     *
     * @return Collection<int, Project>
     */
    public function projectOptions(): Collection
    {
        $category = $this->timesheetCategory;

        if (! $category || ! $category->requires_project) {
            return new Collection;
        }

        return Project::where('is_active', true)
            ->where(fn ($q) => $q
                ->whereHas('categories', fn ($c) => $c->where('timesheet_categories.id', $category->id))
                ->orWhereDoesntHave('categories'))
            ->orderBy('sort')->orderBy('name')->get();
    }

    /**
     * What this card is costed as. Its own field, and nothing else: the category is asked
     * for on the card, so there is no project to infer it from any more. Null means the
     * card still owes an answer, and BoardSuggestions holds its rows back until it has
     * one rather than filing real work under an overhead bucket nobody chose.
     */
    public function effectiveTimesheetCategory(): ?TimesheetCategory
    {
        return $this->timesheet_category_id ? $this->timesheetCategory : null;
    }

    /** The superior who assigned this task. Null for self-created cards. */
    /** @return BelongsTo<Employee, $this> */
    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assigned_by_id');
    }

    /**
     * Human due text for a card: the real due date if set, otherwise a legacy
     * free-text label. Empty string when neither exists. The structured date wins
     * so cards edited through the date picker show the real date even if an old
     * free-text label lingers.
     */
    public function dueText(): string
    {
        return $this->due_at?->format('d M Y') ?? ($this->due_label ?: '');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(WorkItemComment::class)->oldest();
    }

    /**
     * Every visit this card has made to the In Progress column, oldest first.
     *
     * @return HasMany<WorkItemProgressStint, $this>
     */
    public function progressStints(): HasMany
    {
        return $this->hasMany(WorkItemProgressStint::class)->orderBy('started_at');
    }

    /**
     * People included on this card beyond its owner. The same shared card appears
     * on every participant's board; they may view / move / comment but not edit.
     *
     * @return BelongsToMany<Employee, $this>
     */
    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'work_item_participant');
    }
}
