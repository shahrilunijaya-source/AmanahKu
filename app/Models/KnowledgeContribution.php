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

    /**
     * Mark an employee's monthly contribution as fulfilled for a specific calendar month.
     *
     * Shared by the Knowledge Bank (a written lesson) and the TOT board (presenting a
     * session). Takes an explicit year and month rather than reading now(), because a TOT
     * session marked done late must still credit the month it was held in.
     */
    public static function mark(Employee $employee, int $year, int $month): void
    {
        static::updateOrCreate(
            ['employee_id' => $employee->id, 'year' => $year, 'month' => $month],
            ['submitted' => true],
        );
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
