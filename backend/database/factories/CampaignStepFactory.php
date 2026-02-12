<?php

namespace Database\Factories;

use App\Models\Campaign;
use App\Models\CampaignStep;
use App\Models\Template;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CampaignStep>
 */
class CampaignStepFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<CampaignStep>
     */
    protected $model = CampaignStep::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'campaign_id' => Campaign::factory(),
            'template_id' => Template::factory(),
            'name' => 'Step '.fake()->numberBetween(1, 9),
            'step_order' => fake()->numberBetween(1, 10),
            'channel' => 'email',
            'delay_minutes' => 0,
            'is_active' => true,
            'settings' => [],
        ];
    }
}
