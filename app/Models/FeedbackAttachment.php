<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A file a reporter attaches to a feedback item — a pasted screenshot or an uploaded
 * document. Files live on the private 'local' disk and are only ever reached through
 * FeedbackController::attachment (auth-gated stream), never a public URL.
 */
class FeedbackAttachment extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected $casts = ['size' => 'integer'];

    public function feedbackItem(): BelongsTo
    {
        return $this->belongsTo(FeedbackItem::class);
    }

    /** Images render as inline thumbnails in the inbox; everything else as a download chip. */
    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime, 'image/');
    }
}
