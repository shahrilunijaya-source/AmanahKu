<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file a reporter attaches to a ticket — a pasted screenshot or an uploaded document,
 * relevant to Bug/Idea tickets in particular. Files live on the private 'local' disk and
 * are only ever reached through HelpdeskController::attachment (auth-gated stream), never
 * a public URL.
 */
class TicketAttachment extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected $casts = ['size' => 'integer'];

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** Images render as inline thumbnails; everything else as a download chip. */
    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime, 'image/');
    }
}
