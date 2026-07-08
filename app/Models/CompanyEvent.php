<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CompanyEvent extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['event_date' => 'date'];
    }

    public function rsvps(): HasMany
    {
        return $this->hasMany(EventRsvp::class);
    }
}
