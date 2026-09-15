<?php

namespace Database\Factories;

use App\Models\CompanyCategory;
use App\Models\CompanyInvite;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CompanyInvite>
 */
class CompanyInviteFactory extends Factory
{
    protected $model = CompanyInvite::class;

    /**
     * Pending, Stage 3 (the spec default), seven days out, created by a fresh user.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'token' => Str::random(40),
            'note' => fake()->company(),
            'company_category_id' => CompanyCategory::where('level', 3)->value('id'),
            'expires_at' => now()->addDays(7),
            'used_at' => null,
            'used_by_tenant_id' => null,
            'created_by_user_id' => User::factory(),
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->subDay(),
        ]);
    }

    public function usedBy(Tenant $tenant): static
    {
        return $this->state(fn (array $attributes) => [
            'used_at' => now()->subHour(),
            'used_by_tenant_id' => $tenant->id,
        ]);
    }
}
