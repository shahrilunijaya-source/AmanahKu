<?php

namespace Database\Factories;

use App\Models\GreetingLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GreetingLine>
 */
class GreetingLineFactory extends Factory
{
    protected $model = GreetingLine::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $trigger = 'morning';

        return [
            'bucket' => GreetingLine::TRIGGERS[$trigger]['bucket'],
            'trigger' => $trigger,
            'text_en' => 'Morning, {name}.',
            'text_ms' => 'Pagi, {name}.',
            'approved_at' => now(),
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => ['approved_at' => null]);
    }
}
