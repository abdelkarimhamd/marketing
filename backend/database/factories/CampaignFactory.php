<?php

namespace Database\Factories;

use App\Models\Campaign;
use App\Models\Segment;
use App\Models\Team;
use App\Models\Template;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Campaign>
 */
class CampaignFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<Campaign>
     */
    protected $model = Campaign::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = 'Campaign '.fake()->unique()->numberBetween(1000, 9999);

        return [
            'tenant_id' => Tenant::factory(),
            'segment_id' => Segment::factory(),
            'template_id' => Template::factory(),
            'team_id' => Team::factory(),
            'created_by' => User::factory()->tenantAdmin(),
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => fake()->sentence(),
            'channel' => 'email',
            'campaign_type' => Campaign::TYPE_BROADCAST,
            'status' => Campaign::STATUS_DRAFT,
            'start_at' => null,
            'end_at' => null,
            'launched_at' => null,
            'settings' => [],
            'metrics' => [],
        ];
    }

    /**
     * State for drip campaigns.
     */
    public function drip(): static
    {
        return $this->state(fn (): array => [
            'campaign_type' => Campaign::TYPE_DRIP,
            'status' => Campaign::STATUS_DRAFT,
        ]);
    }
}
