<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AttachmentManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_admin_can_upload_list_download_and_delete_lead_attachments(): void
    {
        Storage::fake('local');

        config([
            'attachments.disk' => 'local',
            'attachments.allowed_mime_types' => ['application/pdf', 'image/jpeg'],
            'attachments.max_file_size_kb' => 2048,
            'attachments.virus_scan.enabled' => false,
        ]);

        $tenant = Tenant::factory()->create();
        $admin = User::factory()->tenantAdmin()->create(['tenant_id' => $tenant->id]);
        $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

        Sanctum::actingAs($admin);

        $response = $this->post('/api/admin/attachments', [
            'entity_type' => 'lead',
            'entity_id' => $lead->id,
            'kind' => 'contract',
            'source' => 'manual',
            'files' => [
                UploadedFile::fake()->create('contract.pdf', 128, 'application/pdf'),
                UploadedFile::fake()->image('id-card.jpg'),
            ],
        ], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonCount(2, 'attachments');

        $firstId = (int) $response->json('attachments.0.id');
        $firstPath = (string) $response->json('attachments.0.storage_path');

        Storage::disk('local')->assertExists($firstPath);

        $this->getJson('/api/admin/attachments?entity_type=lead&entity_id='.$lead->id)
            ->assertOk()
            ->assertJsonPath('attachments.total', 2);

        $this->get('/api/admin/attachments/'.$firstId.'/download', [
            'Accept' => 'application/octet-stream',
        ])->assertOk();

        $this->deleteJson('/api/admin/attachments/'.$firstId)
            ->assertOk();

        Storage::disk('local')->assertMissing($firstPath);
        $this->assertSoftDeleted('attachments', ['id' => $firstId]);
    }

    public function test_attachment_upload_rejects_infected_file_when_scan_is_enabled(): void
    {
        Storage::fake('local');

        config([
            'attachments.disk' => 'local',
            'attachments.allowed_mime_types' => ['text/plain'],
            'attachments.virus_scan.enabled' => true,
            'attachments.virus_scan.driver' => 'eicar',
        ]);

        $tenant = Tenant::factory()->create();
        $admin = User::factory()->tenantAdmin()->create(['tenant_id' => $tenant->id]);
        $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

        Sanctum::actingAs($admin);

        $this->post('/api/admin/attachments', [
            'entity_type' => 'lead',
            'entity_id' => $lead->id,
            'files' => [
                UploadedFile::fake()->createWithContent(
                    'virus.txt',
                    'X5O!P%@AP[4\\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*'
                ),
            ],
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['files']);

        $this->assertDatabaseCount('attachments', 0);
    }

    public function test_attachment_endpoints_are_scoped_to_tenant(): void
    {
        Storage::fake('local');

        config([
            'attachments.disk' => 'local',
            'attachments.allowed_mime_types' => ['application/pdf'],
            'attachments.virus_scan.enabled' => false,
        ]);

        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $adminA = User::factory()->tenantAdmin()->create(['tenant_id' => $tenantA->id]);
        $adminB = User::factory()->tenantAdmin()->create(['tenant_id' => $tenantB->id]);
        $leadA = Lead::factory()->create(['tenant_id' => $tenantA->id]);

        Sanctum::actingAs($adminA);

        $upload = $this->post('/api/admin/attachments', [
            'entity_type' => 'lead',
            'entity_id' => $leadA->id,
            'files' => [
                UploadedFile::fake()->create('proposal.pdf', 64, 'application/pdf'),
            ],
        ], ['Accept' => 'application/json'])->assertCreated();

        $attachmentId = (int) $upload->json('attachments.0.id');

        Sanctum::actingAs($adminB);

        $this->getJson('/api/admin/attachments?entity_type=lead&entity_id='.$leadA->id)
            ->assertNotFound();

        $this->get('/api/admin/attachments/'.$attachmentId.'/download', [
            'Accept' => 'application/octet-stream',
        ])->assertNotFound();

        $this->deleteJson('/api/admin/attachments/'.$attachmentId)
            ->assertNotFound();

        $this->assertDatabaseHas('attachments', ['id' => $attachmentId, 'tenant_id' => $tenantA->id]);
    }

    public function test_retention_archive_deletes_expired_attachments(): void
    {
        Storage::fake('local');

        config([
            'attachments.disk' => 'local',
            'attachments.allowed_mime_types' => ['application/pdf'],
            'attachments.virus_scan.enabled' => false,
            'attachments.retention_days' => 1,
        ]);

        $tenant = Tenant::factory()->create();
        $admin = User::factory()->tenantAdmin()->create(['tenant_id' => $tenant->id]);
        $lead = Lead::factory()->create(['tenant_id' => $tenant->id]);

        Sanctum::actingAs($admin);

        $upload = $this->post('/api/admin/attachments', [
            'entity_type' => 'lead',
            'entity_id' => $lead->id,
            'files' => [
                UploadedFile::fake()->create('old.pdf', 64, 'application/pdf'),
            ],
        ], ['Accept' => 'application/json'])->assertCreated();

        $attachmentId = (int) $upload->json('attachments.0.id');
        $attachment = Attachment::query()->withoutTenancy()->findOrFail($attachmentId);
        $path = (string) $attachment->storage_path;

        $attachment->forceFill([
            'expires_at' => now()->subMinute(),
        ])->save();

        $this->postJson('/api/admin/lifecycle/archive')
            ->assertOk()
            ->assertJsonPath('result.deleted_attachments', 1);

        Storage::disk('local')->assertMissing($path);
        $this->assertDatabaseMissing('attachments', ['id' => $attachmentId]);
    }
}
