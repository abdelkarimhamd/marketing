<?php

namespace Database\Factories;

use App\Models\Segment;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Segment>
 */
class SegmentFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<Segment>
     */
    protected $model = Segment::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = 'Segment '.fake()->unique()->numberBetween(1000, 9999);

        return [
            'tenant_id' => Tenant::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => fake()->sentence(),
            'filters' => null,
            'rules_json' => null,
            'settings' => [],
            'is_active' => true,
        ];
    }
}
