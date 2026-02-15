<?php

namespace Tests\Unit;

use App\Models\Tenant;
use App\Services\TenantEmailConfigurationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class TenantEmailConfigurationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_uses_global_defaults_when_tenant_mode_is_platform(): void
    {
        config()->set('messaging.providers.email', 'smtp');
        config()->set('mail.from.address', 'platform@example.test');
        config()->set('mail.from.name', 'Platform');

        $tenant = Tenant::factory()->create([
            'settings' => [
                'providers' => ['email' => 'mock'],
                'email_delivery' => [
                    'mode' => TenantEmailConfigurationService::MODE_PLATFORM,
                    'from_address' => 'tenant@example.test',
                    'from_name' => 'Tenant Name',
                    'use_custom_smtp' => true,
                    'smtp_host' => 'smtp.tenant.test',
                    'smtp_port' => 587,
                    'smtp_username' => 'tenant@example.test',
                    'smtp_password_encrypted' => Crypt::encryptString('tenant-pass'),
                    'smtp_encryption' => 'tls',
                ],
            ],
        ]);

        $service = app(TenantEmailConfigurationService::class);

        $this->assertSame('mock', $service->providerForTenant($tenant->id));
        $this->assertSame('platform@example.test', $service->fromAddressForTenant($tenant->id));
        $this->assertSame('Platform', $service->fromNameForTenant($tenant->id));
        $this->assertNull($service->smtpOverridesForTenant($tenant->id));
    }

    public function test_it_resolves_tenant_mode_sender_and_custom_smtp_overrides(): void
    {
        config()->set('mail.from.address', 'platform@example.test');
        config()->set('mail.from.name', 'Platform');

        $tenant = Tenant::factory()->create([
            'settings' => [
                'email_delivery' => [
                    'mode' => TenantEmailConfigurationService::MODE_TENANT,
                    'from_address' => 'mailer@tenant.example',
                    'from_name' => 'Tenant Mail',
                    'use_custom_smtp' => true,
                    'smtp_host' => 'smtp.tenant.example',
                    'smtp_port' => 465,
                    'smtp_username' => 'mailer@tenant.example',
                    'smtp_password_encrypted' => Crypt::encryptString('tenant-secret'),
                    'smtp_encryption' => 'ssl',
                ],
            ],
        ]);

        $service = app(TenantEmailConfigurationService::class);
        $overrides = $service->smtpOverridesForTenant($tenant->id);

        $this->assertSame('mailer@tenant.example', $service->fromAddressForTenant($tenant->id));
        $this->assertSame('Tenant Mail', $service->fromNameForTenant($tenant->id));
        $this->assertIsArray($overrides);
        $this->assertSame('smtp.tenant.example', $overrides['host']);
        $this->assertSame(465, $overrides['port']);
        $this->assertSame('mailer@tenant.example', $overrides['username']);
        $this->assertSame('tenant-secret', $overrides['password']);
        $this->assertSame('ssl', $overrides['encryption']);
    }
}
