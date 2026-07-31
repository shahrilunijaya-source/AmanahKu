<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file attached to a direct Message — a pasted/snapped image or an uploaded document.
 * Files live on the private 'local' disk and are only ever reached through
 * MessageController::attachment (participant-gated stream), never a public URL.
 */
class MessageAttachment extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected $casts = ['size' => 'integer'];

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /** Images render as inline thumbnails; everything else as a download chip. */
    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime, 'image/');
    }
}
