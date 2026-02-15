<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RequestContextLoggingTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_responses_include_generated_request_id_header(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->tenantAdmin()->create(['tenant_id' => $tenant->id]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/admin/dashboard?tenant_id='.$tenant->id);

        $response->assertOk();
        $response->assertHeader('X-Request-ID');
        $this->assertNotSame('', (string) $response->headers->get('X-Request-ID', ''));
    }

    public function test_api_responses_preserve_incoming_request_id_header(): void
    {
        $requestId = 'qa-request-id-123';

        $response = $this->withHeader('X-Request-ID', $requestId)
            ->getJson('/api/ping');

        $response->assertOk();
        $response->assertHeader('X-Request-ID', $requestId);
    }
}
