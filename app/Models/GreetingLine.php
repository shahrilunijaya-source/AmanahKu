<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\GreetingLineFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * CR-33: one rotating dashboard-greeting line. HR curates a bank per tenant
 * (approved lines) plus employee-suggested ones awaiting approval. The
 * dashboard picker (App\Support\GreetingBank::pick) walks BUCKETS in priority
 * order and rotates a random approved line matching an active trigger.
 *
 * @property Carbon|null $approved_at
 */
class GreetingLine extends Model
{
    /** @use HasFactory<GreetingLineFactory> */
    use BelongsToTenant, HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['approved_at' => 'datetime'];
    }

    /**
     * Priority order the picker walks: the first bucket with a matching approved
     * line wins, so a birthday (personal) always beats "it's Friday" (day).
     *
     * @var list<string>
     */
    public const BUCKETS = ['personal', 'situation', 'day', 'time'];

    /**
     * trigger => [bucket, English label, Malay label] for the settings screen's
     * trigger picker and grouping. Order here is display order within a bucket.
     *
     * @var array<string, array{bucket: string, label_en: string, label_ms: string}>
     */
    public const TRIGGERS = [
        'birthday' => ['bucket' => 'personal', 'label_en' => 'Birthday', 'label_ms' => 'Hari lahir'],
        'holiday_eve' => ['bucket' => 'situation', 'label_en' => 'Holiday eve', 'label_ms' => 'Malam sebelum cuti'],
        'overdue' => ['bucket' => 'situation', 'label_en' => 'Overdue tasks', 'label_ms' => 'Tugasan tertunggak'],
        'not_clocked_in' => ['bucket' => 'situation', 'label_en' => 'Not clocked in', 'label_ms' => 'Belum clock in'],
        'monday' => ['bucket' => 'day', 'label_en' => 'Monday', 'label_ms' => 'Isnin'],
        'friday' => ['bucket' => 'day', 'label_en' => 'Friday', 'label_ms' => 'Jumaat'],
        'weekend' => ['bucket' => 'day', 'label_en' => 'Weekend', 'label_ms' => 'Hujung minggu'],
        'morning' => ['bucket' => 'time', 'label_en' => 'Morning', 'label_ms' => 'Pagi'],
        'afternoon' => ['bucket' => 'time', 'label_en' => 'Afternoon', 'label_ms' => 'Tengah hari'],
        'evening' => ['bucket' => 'time', 'label_en' => 'Evening', 'label_ms' => 'Petang'],
        'late' => ['bucket' => 'time', 'label_en' => 'Late (after 10pm)', 'label_ms' => 'Lewat (selepas 10 malam)'],
    ];

    public function suggestedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'suggested_by');
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->whereNotNull('approved_at');
    }
}
