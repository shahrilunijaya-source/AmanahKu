<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A picture posted on an event's post-event page. Files live on the private 'local' disk. */
class EventPhoto extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(CompanyEvent::class, 'company_event_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
