<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Account>
 */
class AccountFactory extends Factory
{
    protected $model = Account::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'owner_user_id' => User::factory(),
            'name' => fake()->company(),
            'domain' => fake()->domainName(),
            'industry' => fake()->randomElement(['clinic', 'real_estate', 'restaurant', 'agency']),
            'size' => fake()->randomElement(['1-10', '11-50', '51-200', '200+']),
            'city' => fake()->city(),
            'country' => fake()->countryCode(),
            'notes' => fake()->sentence(),
            'settings' => [],
        ];
    }
}
