<?php

namespace Database\Factories;

use App\Models\Attachment;
use App\Models\Brand;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\ProposalTemplate;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Proposal>
 */
class ProposalFactory extends Factory
{
    /**
     * @var class-string<Proposal>
     */
    protected $model = Proposal::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'brand_id' => null,
            'lead_id' => Lead::factory(),
            'proposal_template_id' => ProposalTemplate::factory(),
            'created_by' => User::factory(),
            'pdf_attachment_id' => null,
            'version_no' => 1,
            'status' => 'draft',
            'service' => fake()->randomElement(['crm', 'ads', 'consulting']),
            'currency' => 'USD',
            'quote_amount' => fake()->randomFloat(2, 1000, 10000),
            'title' => 'Proposal',
            'subject' => 'Proposal for client',
            'body_html' => '<p>Proposal body</p>',
            'body_text' => 'Proposal body',
            'share_token' => Str::random(80),
            'public_url' => null,
            'accepted_by' => null,
            'sent_at' => null,
            'opened_at' => null,
            'accepted_at' => null,
            'meta' => [],
        ];
    }
}
