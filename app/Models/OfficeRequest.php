<?php

namespace App\Models;

use App\Models\Concerns\AuditsChanges;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasAuditedFields;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * CR-21: a facilities/pantry/IT request raised by any staff member. Routes to one
 * `work_items` card (`work_item_id`) owned by the resolved Finance Manager, tracked here
 * separately so the public board (votes, admin note, closing note, reopen window) has a
 * home that isn't the generic board card shape.
 *
 * @property int $employee_id
 * @property string $category
 * @property string $title
 * @property string $status
 * @property string $urgency
 * @property int $votes
 * @property int|null $work_item_id
 * @property Carbon|null $done_at
 */
class OfficeRequest extends Model implements HasAuditedFields
{
    use AuditsChanges;
    use BelongsToTenant;

    protected $guarded = [];

    public const CATEGORIES = ['facilities', 'vehicle', 'pantry', 'it', 'cleaning', 'other'];

    /** QA F2 (CR-21 scope 1): the six categories as the spec names them, EN and BM. */
    public const CATEGORY_LABELS = [
        'facilities' => ['Facilities repair', 'Baiki kemudahan'],
        'vehicle' => ['Vehicle', 'Kenderaan'],
        'pantry' => ['Pantry & supplies', 'Pantri & bekalan'],
        'it' => ['IT & equipment', 'IT & peralatan'],
        'cleaning' => ['Cleaning', 'Pembersihan'],
        'other' => ['Other', 'Lain-lain'],
    ];

    public const URGENCIES = ['low', 'normal', 'urgent'];

    public const STATUSES = ['open', 'in_progress', 'done'];

    protected function casts(): array
    {
        return ['done_at' => 'datetime'];
    }

    /** Fields the Global Clause requires an audit entry for on change (raise/create is always audited by the trait). */
    public function audited(): array
    {
        return ['category', 'urgency', 'status', 'admin_note', 'closing_note', 'done_at'];
    }

    /** @return BelongsTo<Employee, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return BelongsTo<WorkItem, $this> */
    public function workItem(): BelongsTo
    {
        return $this->belongsTo(WorkItem::class);
    }

    /** @return HasMany<OfficeRequestVote, $this> */
    public function votesCast(): HasMany
    {
        return $this->hasMany(OfficeRequestVote::class);
    }

    /** @return HasMany<OfficeRequestComment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(OfficeRequestComment::class)->orderBy('created_at');
    }

    public function categoryLabel(bool $malay = false): string
    {
        return self::CATEGORY_LABELS[$this->category][$malay ? 1 : 0] ?? ucfirst((string) $this->category);
    }

    /** Reopen window: the requester only, within 3 calendar days of done_at. */
    public function withinReopenWindow(): bool
    {
        return $this->done_at !== null && abs(Carbon::now()->diffInDays($this->done_at)) <= 3;
    }
}
