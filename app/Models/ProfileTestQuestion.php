<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProfileTestQuestion extends Model
{
    protected $fillable = ['section', 'prompt_en', 'position'];

    protected $casts = ['position' => 'integer'];

    public function options(): HasMany
    {
        return $this->hasMany(ProfileTestOption::class)->orderBy('position');
    }
}
