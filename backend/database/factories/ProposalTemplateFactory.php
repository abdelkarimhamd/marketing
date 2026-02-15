<?php

namespace Database\Factories;

use App\Models\Brand;
use App\Models\ProposalTemplate;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProposalTemplate>
 */
class ProposalTemplateFactory extends Factory
{
    /**
     * @var class-string<ProposalTemplate>
     */
    protected $model = ProposalTemplate::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->company().' Proposal';

        return [
            'tenant_id' => Tenant::factory(),
            'brand_id' => null,
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numerify('###'),
            'service' => fake()->randomElement(['crm', 'ads', 'consulting']),
            'currency' => 'USD',
            'subject' => 'Proposal for {{full_name}}',
            'body_html' => '<h1>Proposal</h1><p>Hello {{full_name}}</p><p>Service: {{service}}</p><p>Amount: {{proposal.quote_amount}}</p>',
            'body_text' => 'Proposal for {{full_name}} - {{service}} - {{proposal.quote_amount}}',
            'settings' => [],
            'is_active' => true,
        ];
    }
}
