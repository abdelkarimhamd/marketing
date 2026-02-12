<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicLeadIntakeTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_lead_can_be_created_with_tenant_slug_header(): void
    {
        $tenant = Tenant::factory()->create([
            'slug' => 'acme',
            'domain' => 'acme.example.test',
        ]);

        $response = $this->withHeader('X-Tenant-Slug', $tenant->slug)
            ->postJson('/api/public/leads', [
                'first_name' => 'Jane',
                'last_name' => 'Doe',
                'email' => 'jane.doe@example.test',
                'source' => 'website_form',
                'tags' => ['pricing', 'high-intent'],
            ]);

        $response->assertCreated()
            ->assertJsonPath('lead.tenant_id', $tenant->id)
            ->assertJsonPath('lead.email', 'jane.doe@example.test')
            ->assertJsonPath('lead.status', 'new');

        $this->assertDatabaseHas('leads', [
            'tenant_id' => $tenant->id,
            'email' => 'jane.doe@example.test',
            'status' => 'new',
        ]);

        $this->assertDatabaseHas('activities', [
            'tenant_id' => $tenant->id,
            'type' => 'lead.intake.created',
            'subject_type' => 'App\Models\Lead',
        ]);
    }

    public function test_public_lead_can_be_created_with_api_key_resolution(): void
    {
        $tenant = Tenant::factory()->create();
        $plainTextKey = 'public-key-test';

        ApiKey::query()->withoutTenancy()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Public Intake',
            'prefix' => 'public-key-test',
            'key_hash' => hash('sha256', $plainTextKey),
            'secret' => $plainTextKey,
            'abilities' => ['public:leads:write'],
        ]);

        $response = $this->withHeader('X-Api-Key', $plainTextKey)
            ->postJson('/api/public/leads', [
                'email' => 'api.source@example.test',
                'source' => 'api',
            ]);

        $response->assertCreated()
            ->assertJsonPath('lead.tenant_id', $tenant->id)
            ->assertJsonPath('lead.source', 'api');
    }

    public function test_public_lead_can_be_created_from_mapped_origin_domain(): void
    {
        $tenant = Tenant::factory()->create([
            'domain' => 'forms.example.test',
        ]);

        $response = $this->withHeader('Origin', 'https://forms.example.test')
            ->postJson('/api/public/leads', [
                'email' => 'origin.domain@example.test',
                'source' => 'website',
            ]);

        $response->assertCreated()
            ->assertJsonPath('lead.tenant_id', $tenant->id);
    }

    public function test_honeypot_blocks_spam_submissions(): void
    {
        $tenant = Tenant::factory()->create(['slug' => 'honeypot-tenant']);

        $this->withHeader('X-Tenant-Slug', $tenant->slug)
            ->postJson('/api/public/leads', [
                'email' => 'spam@example.test',
                'website' => 'https://spam.bot',
            ])
            ->assertUnprocessable();

        $this->assertDatabaseCount('leads', 0);
    }

    public function test_rate_limit_is_applied_to_public_lead_intake(): void
    {
        $tenant = Tenant::factory()->create(['slug' => 'throttle-tenant']);

        for ($i = 1; $i <= 30; $i++) {
            $this->withHeader('X-Tenant-Slug', $tenant->slug)
                ->postJson('/api/public/leads', [
                    'email' => "lead-{$i}@example.test",
                ])
                ->assertCreated();
        }

        $this->withHeader('X-Tenant-Slug', $tenant->slug)
            ->postJson('/api/public/leads', [
                'email' => 'blocked@example.test',
            ])
            ->assertTooManyRequests();
    }
}
