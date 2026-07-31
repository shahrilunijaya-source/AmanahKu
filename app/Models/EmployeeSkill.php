<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeSkill extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    /** Human-readable proficiency labels for levels 1–5. */
    private const LEVEL_LABELS = [
        1 => 'Novice',
        2 => 'Beginner',
        3 => 'Competent',
        4 => 'Proficient',
        5 => 'Expert',
    ];

    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'verified' => 'boolean',
            'self_rated_at' => 'datetime',
        ];
    }

    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** Word label for the numeric proficiency level (1=Novice … 5=Expert). */
    public function getLevelLabelAttribute(): string
    {
        return self::LEVEL_LABELS[$this->level] ?? 'Unrated';
    }
}
