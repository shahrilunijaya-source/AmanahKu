<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A piece of onboarding content for one standard checklist item. position_id NULL is the
 * company-wide default; a row with a position_id overrides it for hires in that position.
 * Any mix of the four content types may be set: text body, video URL, file, acknowledge box.
 */
class OnboardingResource extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['requires_ack' => 'boolean'];
    }

    public function position(): BelongsTo
    {
        return $this->belongsTo(Position::class);
    }

    /** True when this record actually carries something worth opening. */
    public function hasContent(): bool
    {
        return filled($this->body) || filled($this->video_url) || filled($this->file_path);
    }

    /**
     * Resolve the content each of $itemKeys should show for a hire in $positionId. A
     * position-specific override wins; otherwise the NULL default is used. Returns a map
     * item_key => OnboardingResource (only for keys that resolved to a row). Tenant scope
     * is applied by BelongsToTenant, so this only ever sees the active tenant's library.
     *
     * @param  list<string>  $itemKeys
     * @return array<string, OnboardingResource>
     */
    public static function resolveFor(array $itemKeys, ?int $positionId): array
    {
        if ($itemKeys === []) {
            return [];
        }

        /** @var Collection<int, OnboardingResource> $rows */
        $rows = static::whereIn('item_key', $itemKeys)
            ->where(fn ($q) => $q->whereNull('position_id')->orWhere('position_id', $positionId))
            ->get();

        $resolved = [];
        foreach ($rows as $row) {
            $existing = $resolved[$row->item_key] ?? null;
            // Prefer the position-specific override over the NULL default.
            if (! $existing || ($row->position_id !== null && $existing->position_id === null)) {
                $resolved[$row->item_key] = $row;
            }
        }

        return $resolved;
    }
}
