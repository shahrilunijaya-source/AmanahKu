<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * A suggested or template poll question. `template=1, approved=1` rows are the
 * only source a `kind=who` poll's question text may come from (CR-25 Rules:
 * "'Who' questions only from a positive/funny template list"). A plain
 * suggestion (`template=0`) starts `approved=0` and is visible to HR only —
 * this session builds no route to promote one into the template bank, see
 * docs/build/OPEN.md.
 */
class PlotTwistQuestion extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['template' => 'boolean', 'approved' => 'boolean'];
    }
}
