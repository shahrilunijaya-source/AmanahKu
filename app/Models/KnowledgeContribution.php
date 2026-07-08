<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KnowledgeContribution extends Model
{
    use BelongsToTenant;

    protected $table = 'knowledge_monthly_contributions';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['submitted' => 'boolean'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
