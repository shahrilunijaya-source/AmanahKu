<?php

namespace Database\Factories;

use App\Models\WorkDayRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkDayRule>
 */
class WorkDayRuleFactory extends Factory
{
    protected $model = WorkDayRule::class;

    /**
     * Defaults to the TOT rule: first Saturday, 09:00-13:00, half day. Pass tenant_id.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'weekday' => 6,
            'weeks' => [1],
            'start_time' => '09:00',
            'end_time' => '13:00',
            'counts' => WorkDayRule::HALF,
        ];
    }
}
