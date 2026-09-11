<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One port call (docs/build/contracts/ports.md): written before the adapter runs, then
 * marked sent, failed or skipped. The row is the record of intent that deferred features
 * are graded on, so it is never deleted by app code.
 *
 * @property string $port
 * @property string $method
 * @property array<string, mixed>|null $payload
 * @property string $status
 * @property int $attempts
 * @property string|null $external_id
 * @property string|null $error
 * @property Carbon|null $sent_at
 */
class PortOutbox extends Model
{
    use BelongsToTenant;

    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    protected $table = 'port_outbox';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'sent_at' => 'datetime',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
