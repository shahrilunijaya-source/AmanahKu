<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MeetingRoom extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(RoomBooking::class);
    }
}
