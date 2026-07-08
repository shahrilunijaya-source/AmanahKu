<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * An Employee Assistance Programme resource — helpline, counselling, financial or
 * legal wellness programme — browsable by all staff, managed by HR/management.
 */
class EapResource extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
