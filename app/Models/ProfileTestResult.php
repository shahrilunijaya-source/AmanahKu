<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProfileTestResult extends Model
{
    protected $fillable = [
        'employee_id', 'self_goal', 'self_strengths', 'self_weaknesses', 'self_interests',
        'self_mbti', 'working_style_answers', 'colour_answers', 'animal_archetype', 'totals', 'submitted_at',
    ];

    protected $casts = [
        'working_style_answers' => 'array',
        'colour_answers' => 'array',
        'totals' => 'array',
        'submitted_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
