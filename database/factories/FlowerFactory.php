<?php

namespace Database\Factories;

use App\Models\Flower;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Flower>
 */
class FlowerFactory extends Factory
{
    protected $model = Flower::class;

    /**
     * giver_id and recipient_id have no sensible default (tenant-scoped Employee
     * rows) — callers always pass them explicitly, e.g.
     * Flower::factory()->for($giver, 'giver')->for($recipient, 'recipient')->create().
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'note' => fake()->sentence(),
            'month' => now()->format('Y-m'),
        ];
    }

    public function hidden(): static
    {
        return $this->state(fn (array $attributes) => [
            'hidden_at' => now(),
        ]);
    }
}
