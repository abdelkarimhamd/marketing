<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MediaLibraryManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_admin_can_upload_list_download_and_delete_media_assets(): void
    {
        Storage::fake('local');

        config([
            'attachments.disk' => 'local',
            'attachments.allowed_mime_types' => ['application/pdf', 'image/png'],
            'attachments.max_file_size_kb' => 2048,
            'attachments.virus_scan.enabled' => false,
        ]);

        $tenant = Tenant::factory()->create();
        $admin = User::factory()->tenantAdmin()->create(['tenant_id' => $tenant->id]);

        Sanctum::actingAs($admin);

        $upload = $this->post('/api/admin/media-library', [
            'files' => [
                UploadedFile::fake()->image('banner.png'),
                UploadedFile::fake()->create('brochure.pdf', 128, 'application/pdf'),
            ],
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonCount(2, 'assets');

        $assetId = (int) $upload->json('assets.0.id');
        $assetPath = (string) $upload->json('assets.0.storage_path');

        Storage::disk('local')->assertExists($assetPath);

        $this->getJson('/api/admin/media-library')
            ->assertOk()
            ->assertJsonPath('assets.total', 2);

        $this->get('/api/admin/media-library/'.$assetId.'/download', [
            'Accept' => 'application/octet-stream',
        ])->assertOk();

        $this->deleteJson('/api/admin/media-library/'.$assetId)
            ->assertOk();

        Storage::disk('local')->assertMissing($assetPath);
        $this->assertSoftDeleted('attachments', ['id' => $assetId, 'entity_type' => 'media_library']);
    }

    public function test_media_library_assets_are_tenant_scoped(): void
    {
        Storage::fake('local');

        config([
            'attachments.disk' => 'local',
            'attachments.allowed_mime_types' => ['application/pdf'],
            'attachments.max_file_size_kb' => 2048,
            'attachments.virus_scan.enabled' => false,
        ]);

        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $adminA = User::factory()->tenantAdmin()->create(['tenant_id' => $tenantA->id]);
        $adminB = User::factory()->tenantAdmin()->create(['tenant_id' => $tenantB->id]);

        Sanctum::actingAs($adminA);

        $upload = $this->post('/api/admin/media-library', [
            'files' => [
                UploadedFile::fake()->create('asset.pdf', 64, 'application/pdf'),
            ],
        ], ['Accept' => 'application/json'])->assertCreated();

        $assetId = (int) $upload->json('assets.0.id');

        Sanctum::actingAs($adminB);

        $this->getJson('/api/admin/media-library')
            ->assertOk()
            ->assertJsonPath('assets.total', 0);

        $this->get('/api/admin/media-library/'.$assetId.'/download', [
            'Accept' => 'application/octet-stream',
        ])->assertNotFound();

        $this->deleteJson('/api/admin/media-library/'.$assetId)
            ->assertNotFound();

        $this->assertDatabaseHas('attachments', [
            'id' => $assetId,
            'tenant_id' => $tenantA->id,
            'entity_type' => 'media_library',
        ]);

        $this->assertDatabaseCount('attachments', 1);
        $this->assertSame(
            $tenantA->id,
            (int) Attachment::query()->withoutTenancy()->whereKey($assetId)->value('tenant_id')
        );
    }
}

