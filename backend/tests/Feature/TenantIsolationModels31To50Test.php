<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\Brand;
use App\Models\HighRiskApproval;
use App\Models\IntegrationConnection;
use App\Models\IntegrationEventSubscription;
use App\Models\LeadImportPreset;
use App\Models\LeadImportSchedule;
use App\Models\Playbook;
use App\Models\SavedView;
use App\Models\Tenant;
use App\Models\TenantEncryptionKey;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TenantIsolationModels31To50Test extends TestCase
{
    use RefreshDatabase;

    public function test_new_models_from_features_31_to_50_are_tenant_scoped(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $userA = User::factory()->tenantAdmin()->create(['tenant_id' => $tenantA->id]);
        $userB = User::factory()->tenantAdmin()->create(['tenant_id' => $tenantB->id]);

        $this->seedRowsForTenant($tenantA, $userA);
        $this->seedRowsForTenant($tenantB, $userB);

        /** @var TenantContext $context */
        $context = app(TenantContext::class);
        $context->setTenant((int) $tenantA->id);

        $this->assertTenantScopedResults($tenantA->id);

        $context->setTenant((int) $tenantB->id);

        $this->assertTenantScopedResults($tenantB->id);

        $context->clear();
    }

    private function seedRowsForTenant(Tenant $tenant, User $user): void
    {
        $brand = Brand::query()->withoutTenancy()->create([
            'tenant_id' => $tenant->id,
            'name' => "Brand {$tenant->id}",
            'slug' => "brand-{$tenant->id}",
            'is_active' => true,
            'email_from_address' => "brand{$tenant->id}@example.test",
            'email_from_name' => "Brand {$tenant->id}",
            'landing_domain' => "brand-{$tenant->id}.example.test",
            'settings' => [],
        ]);

        IntegrationConnection::query()->withoutTenancy()->create([
            'tenant_id' => $tenant->id,
            'provider' => 'meta',
            'name' => "Meta {$tenant->id}",
            'config' => [],
            'secrets' => [],
            'capabilities' => ['webhook'],
            'is_active' => true,
        ]);

        IntegrationEventSubscription::query()->withoutTenancy()->create([
            'tenant_id' => $tenant->id,
            'name' => "Subscription {$tenant->id}",
            'endpoint_url' => "https://hooks.example.test/{$tenant->id}",
            'secret' => 'secret',
            'events' => ['lead.created'],
            'is_active' => true,
            'settings' => [],
        ]);

        $preset = LeadImportPreset::query()->withoutTenancy()->create([
            'tenant_id' => $tenant->id,
            'name' => "Preset {$tenant->id}",
            'slug' => "preset-{$tenant->id}",
            'mapping' => ['email' => 'email'],
            'defaults' => ['source' => 'import'],
            'dedupe_policy' => 'merge',
            'dedupe_keys' => ['email'],
            'is_active' => true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        LeadImportSchedule::query()->withoutTenancy()->create([
            'tenant_id' => $tenant->id,
            'preset_id' => $preset->id,
            'name' => "Schedule {$tenant->id}",
            'source_type' => 'url',
            'source_config' => ['url' => "https://imports.example.test/{$tenant->id}.csv"],
            'mapping' => ['email' => 'email'],
            'defaults' => ['status' => 'new'],
            'dedupe_policy' => 'merge',
            'dedupe_keys' => ['email'],
            'schedule_cron' => '0 * * * *',
            'is_active' => true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        SavedView::query()->withoutTenancy()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'name' => "View {$tenant->id}",
            'scope' => 'user',
            'entity' => 'lead',
            'query' => 'qa',
            'filters' => ['status' => 'new'],
            'settings' => [],
        ]);

        Playbook::query()->withoutTenancy()->create([
            'tenant_id' => $tenant->id,
            'created_by' => $user->id,
            'updated_by' => $user->id,
            'name' => "Playbook {$tenant->id}",
            'slug' => "playbook-{$tenant->id}",
            'industry' => 'clinic',
            'stage' => 'qualification',
            'channel' => 'whatsapp',
            'is_active' => true,
            'scripts' => ['intro'],
            'objections' => [],
            'templates' => [],
            'settings' => [],
        ]);

        HighRiskApproval::query()->withoutTenancy()->create([
            'tenant_id' => $tenant->id,
            'action' => 'leads.export',
            'subject_type' => null,
            'subject_id' => null,
            'payload' => ['reason' => 'qa'],
            'fingerprint' => hash('sha256', "tenant-{$tenant->id}-approval"),
            'requested_by' => $user->id,
            'required_approvals' => 1,
            'approved_count' => 0,
            'status' => HighRiskApproval::STATUS_PENDING,
        ]);

        TenantEncryptionKey::query()->withoutTenancy()->create([
            'tenant_id' => $tenant->id,
            'key_version' => 1,
            'key_provider' => 'local',
            'key_reference' => "qa-{$tenant->id}",
            'wrapped_key' => 'encrypted-key-material',
            'status' => TenantEncryptionKey::STATUS_ACTIVE,
            'activated_at' => now(),
            'rotated_by' => $user->id,
        ]);

        Attachment::query()->withoutTenancy()->create([
            'tenant_id' => $tenant->id,
            'lead_id' => null,
            'entity_type' => 'lead',
            'entity_id' => 1,
            'kind' => 'document',
            'source' => 'manual',
            'title' => "Attachment {$tenant->id}",
            'storage_disk' => 'local',
            'storage_path' => "qa/{$tenant->id}/file.pdf",
            'original_name' => 'file.pdf',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => 1000,
            'checksum_sha256' => hash('sha256', (string) $tenant->id),
            'visibility' => 'private',
            'scan_status' => 'clean',
            'uploaded_by' => $user->id,
            'meta' => ['qa' => true],
        ]);

        $brand->refresh();
    }

    private function assertTenantScopedResults(int $tenantId): void
    {
        $this->assertSame(1, Brand::query()->count());
        $this->assertSame($tenantId, (int) Brand::query()->firstOrFail()->tenant_id);

        $this->assertSame(1, IntegrationConnection::query()->count());
        $this->assertSame($tenantId, (int) IntegrationConnection::query()->firstOrFail()->tenant_id);

        $this->assertSame(1, IntegrationEventSubscription::query()->count());
        $this->assertSame($tenantId, (int) IntegrationEventSubscription::query()->firstOrFail()->tenant_id);

        $this->assertSame(1, LeadImportPreset::query()->count());
        $this->assertSame($tenantId, (int) LeadImportPreset::query()->firstOrFail()->tenant_id);

        $this->assertSame(1, LeadImportSchedule::query()->count());
        $this->assertSame($tenantId, (int) LeadImportSchedule::query()->firstOrFail()->tenant_id);

        $this->assertSame(1, SavedView::query()->count());
        $this->assertSame($tenantId, (int) SavedView::query()->firstOrFail()->tenant_id);

        $this->assertSame(1, Playbook::query()->count());
        $this->assertSame($tenantId, (int) Playbook::query()->firstOrFail()->tenant_id);

        $this->assertSame(1, HighRiskApproval::query()->count());
        $this->assertSame($tenantId, (int) HighRiskApproval::query()->firstOrFail()->tenant_id);

        $this->assertSame(1, TenantEncryptionKey::query()->count());
        $this->assertSame($tenantId, (int) TenantEncryptionKey::query()->firstOrFail()->tenant_id);

        $this->assertSame(1, Attachment::query()->count());
        $this->assertSame($tenantId, (int) Attachment::query()->firstOrFail()->tenant_id);
    }
}
