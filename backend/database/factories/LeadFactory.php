<?php

namespace Database\Factories;

use App\Models\Lead;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lead>
 */
class LeadFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<Lead>
     */
    protected $model = Lead::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'brand_id' => null,
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'email_consent' => true,
            'consent_updated_at' => now(),
            'phone' => fake()->phoneNumber(),
            'company' => fake()->company(),
            'city' => fake()->city(),
            'country_code' => fake()->countryCode(),
            'interest' => fake()->randomElement(['solar', 'crm', 'ads']),
            'service' => fake()->randomElement(['consulting', 'implementation', 'support']),
            'title' => fake()->jobTitle(),
            'status' => 'new',
            'source' => 'seed',
            'score' => fake()->numberBetween(0, 100),
            'locale' => 'en',
            'meta' => [],
            'settings' => [],
        ];
    }
}
