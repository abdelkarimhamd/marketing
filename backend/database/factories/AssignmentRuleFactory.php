<?php

namespace Database\Factories;

use App\Models\AssignmentRule;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AssignmentRule>
 */
class AssignmentRuleFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<AssignmentRule>
     */
    protected $model = AssignmentRule::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => 'Rule '.fake()->unique()->numberBetween(1000, 9999),
            'is_active' => true,
            'priority' => 100,
            'strategy' => AssignmentRule::STRATEGY_ROUND_ROBIN,
            'auto_assign_on_intake' => true,
            'auto_assign_on_import' => true,
            'conditions' => [],
            'settings' => [],
        ];
    }
}
