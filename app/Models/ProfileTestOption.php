<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProfileTestOption extends Model
{
    protected $fillable = ['profile_test_question_id', 'label_en', 'animal', 'position'];

    protected $casts = ['position' => 'integer'];

    public function question(): BelongsTo
    {
        return $this->belongsTo(ProfileTestQuestion::class, 'profile_test_question_id');
    }
}
