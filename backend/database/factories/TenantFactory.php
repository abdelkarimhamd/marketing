<?php

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<Tenant>
     */
    protected $model = Tenant::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numerify('###'),
            'public_key' => 'trk_'.Str::lower(Str::random(40)),
            'domain' => fake()->unique()->domainName(),
            'settings' => [],
            'branding' => [
                'logo_url' => null,
                'primary_color' => '#146c94',
                'secondary_color' => '#0c4f6c',
                'accent_color' => '#f59e0b',
                'email_footer' => 'Regards, {{tenant_name}}',
                'landing_theme' => 'default',
            ],
            'timezone' => 'UTC',
            'locale' => 'en',
            'currency' => 'USD',
            'data_residency_region' => 'global',
            'data_residency_locked' => false,
            'sso_required' => false,
            'is_active' => true,
        ];
    }
}
