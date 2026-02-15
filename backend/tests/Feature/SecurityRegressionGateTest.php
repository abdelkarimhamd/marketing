<?php

namespace Tests\Feature;

use App\Models\ExportJob;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SecurityRegressionGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_lead_endpoint_is_rate_limited_per_tenant_and_ip(): void
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);

        for ($attempt = 1; $attempt <= 30; $attempt++) {
            $this->withHeader('X-Tenant-ID', (string) $tenant->id)
                ->postJson('/api/public/leads', [
                    'email' => "rate-limit-{$attempt}@example.test",
                    'source' => 'website',
                ])
                ->assertCreated();
        }

        $this->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->postJson('/api/public/leads', [
                'email' => 'rate-limit-final@example.test',
                'source' => 'website',
            ])
            ->assertStatus(429);
    }

    public function test_export_download_endpoints_do_not_leak_across_tenants(): void
    {
        Storage::fake('local');

        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $adminA = User::factory()->tenantAdmin()->create(['tenant_id' => $tenantA->id]);
        $adminB = User::factory()->tenantAdmin()->create(['tenant_id' => $tenantB->id]);

        Storage::disk('local')->put('exports/tenant-a/sample.csv', "id,name\n1,Lead A\n");

        $exportJob = ExportJob::query()->withoutTenancy()->create([
            'tenant_id' => $tenantA->id,
            'type' => 'leads',
            'destination' => 'download',
            'status' => 'completed',
            'payload' => [],
            'file_path' => 'exports/tenant-a/sample.csv',
            'completed_at' => now(),
        ]);

        Sanctum::actingAs($adminA);

        $this->getJson('/api/admin/exports/'.$exportJob->id.'?tenant_id='.$tenantA->id)
            ->assertOk()
            ->assertJsonPath('job.id', $exportJob->id);

        Sanctum::actingAs($adminB);

        $this->getJson('/api/admin/exports/'.$exportJob->id.'?tenant_id='.$tenantB->id)
            ->assertNotFound();

        $this->get('/api/admin/exports/'.$exportJob->id.'/stream?tenant_id='.$tenantB->id)
            ->assertNotFound();
    }

    public function test_scheduled_import_rejects_non_public_source_urls(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->tenantAdmin()->create(['tenant_id' => $tenant->id]);

        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/lead-import/schedules', [
            'name' => 'Blocked localhost import',
            'source_type' => 'url',
            'source_config' => [
                'url' => 'http://localhost/leads.csv',
                'format' => 'csv',
            ],
            'schedule_cron' => '* * * * *',
            'timezone' => 'UTC',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'source_config.url must target a public host.');

        $this->postJson('/api/admin/lead-import/schedules', [
            'name' => 'Blocked private-ip import',
            'source_type' => 'url',
            'source_config' => [
                'url' => 'http://127.0.0.1/leads.csv',
                'format' => 'csv',
            ],
            'schedule_cron' => '* * * * *',
            'timezone' => 'UTC',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'source_config.url must target a public host.');
    }
}
