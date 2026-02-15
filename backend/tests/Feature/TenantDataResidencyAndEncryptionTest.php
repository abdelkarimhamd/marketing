<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantEncryptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenantDataResidencyAndEncryptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_admin_can_set_data_residency_and_rotate_tenant_key(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->tenantAdmin()->create([
            'tenant_id' => $tenant->id,
        ]);

        Sanctum::actingAs($admin);

        $this->putJson('/api/admin/settings?tenant_id='.$tenant->id, [
            'data_residency_region' => 'eu',
            'email_delivery' => [
                'mode' => 'tenant',
                'from_address' => 'mailer@tenant.example',
                'from_name' => 'Tenant Mail',
                'use_custom_smtp' => true,
                'smtp_host' => 'smtp.tenant.example',
                'smtp_port' => 587,
                'smtp_username' => 'mailer@tenant.example',
                'smtp_password' => 'rotate-me-secret',
                'smtp_encryption' => 'tls',
            ],
        ])->assertOk()
            ->assertJsonPath('tenant.data_residency_region', 'eu')
            ->assertJsonPath('settings.encryption.active_key_version', 1)
            ->assertJsonPath('settings.email_delivery.has_smtp_password', true);

        $tenant->refresh();
        $before = (string) data_get($tenant->settings, 'email_delivery.smtp_password_encrypted', '');

        $this->assertNotSame('', $before);
        $this->assertTrue(Str::startsWith($before, 'tenantenc:v1:1:'));

        $this->postJson('/api/admin/settings/encryption/rotate?tenant_id='.$tenant->id, [
            'reason' => 'Quarterly enterprise key rotation',
        ])->assertOk()
            ->assertJsonPath('encryption.active_key_version', 2)
            ->assertJsonPath('rotation.re_encrypted_values', 1);

        $tenant->refresh();
        $after = (string) data_get($tenant->settings, 'email_delivery.smtp_password_encrypted', '');

        $this->assertNotSame($before, $after);
        $this->assertSame(
            'rotate-me-secret',
            app(TenantEncryptionService::class)->decryptForTenant($tenant->id, $after)
        );

        $this->assertDatabaseHas('tenant_encryption_keys', [
            'tenant_id' => $tenant->id,
            'key_version' => 1,
            'status' => 'retired',
        ]);

        $this->assertDatabaseHas('tenant_encryption_keys', [
            'tenant_id' => $tenant->id,
            'key_version' => 2,
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('activities', [
            'tenant_id' => $tenant->id,
            'type' => 'tenant.encryption_key.rotated',
            'subject_type' => Tenant::class,
            'subject_id' => $tenant->id,
        ]);
    }

    public function test_data_residency_cannot_change_when_locked(): void
    {
        $tenant = Tenant::factory()->create([
            'data_residency_region' => 'eu',
            'data_residency_locked' => true,
        ]);

        $admin = User::factory()->tenantAdmin()->create([
            'tenant_id' => $tenant->id,
        ]);

        Sanctum::actingAs($admin);

        $this->putJson('/api/admin/settings?tenant_id='.$tenant->id, [
            'data_residency_region' => 'us',
        ])->assertStatus(422)
            ->assertJsonPath('message', 'Data residency region is locked for this tenant.');
    }
}

