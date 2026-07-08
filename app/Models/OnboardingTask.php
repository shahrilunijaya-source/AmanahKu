<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OnboardingTask extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['done' => 'boolean'];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(OnboardingProfile::class, 'onboarding_profile_id');
    }
}
