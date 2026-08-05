<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ticket extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    /** The employee who raised the ticket. */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** The privileged staff member assigned to handle the ticket. */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assignee_employee_id');
    }

    /** Screenshots + documents the reporter attached, in upload order. */
    public function attachments(): HasMany
    {
        return $this->hasMany(TicketAttachment::class)->oldest('id');
    }
}
