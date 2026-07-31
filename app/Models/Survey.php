<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Survey extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    public function responses(): HasMany
    {
        return $this->hasMany(SurveyResponse::class);
    }
}
