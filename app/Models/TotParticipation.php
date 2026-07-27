<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TotParticipation extends Model
{
    use BelongsToTenant;

    protected $table = 'tot_participation';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['watched_at' => 'datetime'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(TotSession::class, 'session_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
