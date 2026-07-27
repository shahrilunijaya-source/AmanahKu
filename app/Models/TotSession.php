<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class TotSession extends Model
{
    use BelongsToTenant;

    /**
     * planned  - slot exists, may or may not have a PIC, not yet held
     * confirmed- PIC and title both set, expected to run
     * done     - the session happened (this is what credits the PIC's month)
     * skipped  - no session that month
     * not_tot  - a non-TOT calendar entry kept for fidelity, never credits anybody
     */
    public const STATUSES = ['planned', 'confirmed', 'done', 'skipped', 'not_tot'];

    /** The only reactions the UI offers. Anything else is rejected with a 422. */
    public const EMOJI = ['👍', '👏', '🔥', '💡', '🤔', '❤️'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'links' => 'array',
            'held_on' => 'date',
        ];
    }

    /**
     * The session date: the first Saturday of the slot's month. It is computed rather than
     * stored, so a slot can exist before anybody decides anything about it.
     */
    public static function firstSaturday(int $year, int $month): Carbon
    {
        return Carbon::parse(sprintf('first saturday of %04d-%02d', $year, $month));
    }

    public function presenter(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'presenter_employee_id');
    }

    /** Optional pointer at a Knowledge Bank lesson on the same topic. Never creates one. */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(KnowledgeEntry::class, 'entry_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(TotComment::class, 'session_id');
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(TotReaction::class, 'session_id');
    }

    public function participations(): HasMany
    {
        return $this->hasMany(TotParticipation::class, 'session_id');
    }
}
