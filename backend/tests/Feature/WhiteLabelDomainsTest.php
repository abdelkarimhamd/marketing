<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Models\User;
use App\Services\TenantDomainDnsService;
use App\Services\TenantEncryptionService;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use Tests\TestCase;

class WhiteLabelDomainsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('web')->get('/_tenant-host-test', function (Request $request) {
            return response()->json([
                'tenant_id' => app(TenantContext::class)->tenantId(),
                'host' => $request->getHost(),
            ]);
        });
    }

    public function test_tenant_admin_can_update_branding_settings(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->tenantAdmin()->create([
            'tenant_id' => $tenant->id,
        ]);

        Sanctum::actingAs($admin);

        $this->putJson('/api/admin/settings?tenant_id='.$tenant->id, [
            'branding' => [
                'logo_url' => 'https://cdn.example.test/acme/logo.svg',
                'primary_color' => '#113355',
                'secondary_color' => '#ddeeff',
                'accent_color' => '#ff9900',
                'email_footer' => 'Thanks,<br>Acme Team',
                'landing_theme' => 'modern',
            ],
        ])->assertOk()
            ->assertJsonPath('branding.primary_color', '#113355')
            ->assertJsonPath('branding.landing_theme', 'modern');

        $tenant->refresh();

        $this->assertSame('https://cdn.example.test/acme/logo.svg', data_get($tenant->branding, 'logo_url'));
        $this->assertSame('#113355', data_get($tenant->branding, 'primary_color'));
        $this->assertSame('modern', data_get($tenant->branding, 'landing_theme'));
    }

    public function test_tenant_admin_can_update_portal_settings(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->tenantAdmin()->create([
            'tenant_id' => $tenant->id,
        ]);

        Sanctum::actingAs($admin);

        $this->putJson('/api/admin/settings?tenant_id='.$tenant->id, [
            'portal' => [
                'enabled' => true,
                'headline' => 'Welcome to Smart Cedra Portal',
                'subtitle' => 'Request quote and track onboarding',
                'source_prefix' => 'smartportal',
                'default_status' => 'new',
                'default_form_slug' => 'portal-intake',
                'default_tags' => ['portal', 'vip'],
                'tracking_token_ttl_days' => 365,
                'features' => [
                    'request_quote' => true,
                    'book_demo' => true,
                    'upload_docs' => true,
                    'track_status' => true,
                ],
                'booking' => [
                    'default_timezone' => 'Asia/Riyadh',
                    'allowed_channels' => ['video', 'phone'],
                ],
            ],
        ])->assertOk()
            ->assertJsonPath('settings.portal.headline', 'Welcome to Smart Cedra Portal')
            ->assertJsonPath('settings.portal.source_prefix', 'smartportal')
            ->assertJsonPath('settings.portal.default_form_slug', 'portal-intake')
            ->assertJsonPath('settings.portal.booking.default_timezone', 'Asia/Riyadh')
            ->assertJsonPath('settings.portal.features.upload_docs', true);

        $tenant->refresh();

        $this->assertSame('smartportal', data_get($tenant->settings, 'portal.source_prefix'));
        $this->assertSame('portal-intake', data_get($tenant->settings, 'portal.default_form_slug'));
        $this->assertSame('Asia/Riyadh', data_get($tenant->settings, 'portal.booking.default_timezone'));
    }

    public function test_tenant_admin_can_configure_platform_or_tenant_email_delivery_settings(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->tenantAdmin()->create([
            'tenant_id' => $tenant->id,
        ]);

        Sanctum::actingAs($admin);

        $this->putJson('/api/admin/settings?tenant_id='.$tenant->id, [
            'email_delivery' => [
                'mode' => 'tenant',
                'from_address' => 'mailer@tenant.example',
                'from_name' => 'Tenant Mail',
                'use_custom_smtp' => true,
                'smtp_host' => 'smtp.tenant.example',
                'smtp_port' => 587,
                'smtp_username' => 'mailer@tenant.example',
                'smtp_password' => 'super-secret-password',
                'smtp_encryption' => 'tls',
            ],
        ])->assertOk()
            ->assertJsonPath('settings.email_delivery.mode', 'tenant')
            ->assertJsonPath('settings.email_delivery.use_custom_smtp', true)
            ->assertJsonPath('settings.email_delivery.has_smtp_password', true);

        $tenant->refresh();
        $encrypted = data_get($tenant->settings, 'email_delivery.smtp_password_encrypted');

        $this->assertIsString($encrypted);
        $this->assertNotSame('super-secret-password', $encrypted);
        $this->assertSame(
            'super-secret-password',
            app(TenantEncryptionService::class)->decryptForTenant($tenant->id, (string) $encrypted)
        );

        $this->getJson('/api/admin/settings?tenant_id='.$tenant->id)
            ->assertOk()
            ->assertJsonPath('settings.email_delivery.mode', 'tenant')
            ->assertJsonPath('settings.email_delivery.from_address', 'mailer@tenant.example')
            ->assertJsonPath('settings.email_delivery.use_custom_smtp', true)
            ->assertJsonPath('settings.email_delivery.smtp_host', 'smtp.tenant.example')
            ->assertJsonPath('settings.email_delivery.has_smtp_password', true);
    }

    public function test_domain_can_be_registered_verified_and_auto_provisioned_for_ssl(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->tenantAdmin()->create([
            'tenant_id' => $tenant->id,
        ]);

        Sanctum::actingAs($admin);

        $create = $this->postJson('/api/admin/domains?tenant_id='.$tenant->id, [
            'host' => 'admin.acme.example',
            'kind' => TenantDomain::KIND_ADMIN,
            'is_primary' => true,
            'cname_target' => 'edge.acme.example',
        ])->assertCreated();

        $domainId = (int) $create->json('domain.id');
        $cnameTarget = (string) $create->json('domain.cname_target');

        $this->mock(TenantDomainDnsService::class, function (MockInterface $mock) use ($cnameTarget): void {
            $mock->shouldReceive('lookupCname')
                ->once()
                ->andReturn([$cnameTarget]);
        });

        $this->postJson('/api/admin/domains/'.$domainId.'/verify?tenant_id='.$tenant->id)
            ->assertOk()
            ->assertJsonPath('domain.verification_status', TenantDomain::VERIFICATION_VERIFIED)
            ->assertJsonPath('domain.ssl_status', TenantDomain::SSL_ACTIVE);

        $this->assertDatabaseHas('tenant_domains', [
            'id' => $domainId,
            'tenant_id' => $tenant->id,
            'host' => 'admin.acme.example',
            'verification_status' => TenantDomain::VERIFICATION_VERIFIED,
            'ssl_status' => TenantDomain::SSL_ACTIVE,
            'is_primary' => 1,
        ]);
    }

    public function test_local_suffix_domain_can_be_verified_without_public_dns_in_testing(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->tenantAdmin()->create([
            'tenant_id' => $tenant->id,
        ]);

        Sanctum::actingAs($admin);

        $create = $this->postJson('/api/admin/domains?tenant_id='.$tenant->id, [
            'host' => 'admin.local-demo.localhost',
            'kind' => TenantDomain::KIND_ADMIN,
            'is_primary' => true,
            'cname_target' => 'tenant.marketion.local',
        ])->assertCreated();

        $domainId = (int) $create->json('domain.id');

        $this->mock(TenantDomainDnsService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('lookupCname');
        });

        $this->postJson('/api/admin/domains/'.$domainId.'/verify?tenant_id='.$tenant->id)
            ->assertOk()
            ->assertJsonPath('domain.verification_status', TenantDomain::VERIFICATION_VERIFIED)
            ->assertJsonPath('domain.ssl_status', TenantDomain::SSL_ACTIVE);
    }

    public function test_public_branding_and_lead_intake_can_resolve_tenant_from_verified_custom_domain(): void
    {
        $tenant = Tenant::factory()->create([
            'slug' => 'acme',
            'branding' => [
                'logo_url' => 'https://cdn.example.test/acme.svg',
                'landing_theme' => 'enterprise',
            ],
        ]);

        TenantDomain::factory()->create([
            'tenant_id' => $tenant->id,
            'host' => 'forms.acme.example',
            'kind' => TenantDomain::KIND_LANDING,
            'is_primary' => true,
            'verification_status' => TenantDomain::VERIFICATION_VERIFIED,
            'verified_at' => now(),
            'ssl_status' => TenantDomain::SSL_ACTIVE,
        ]);

        $this->withHeader('Origin', 'https://forms.acme.example')
            ->getJson('/api/public/branding')
            ->assertOk()
            ->assertJsonPath('tenant.id', $tenant->id)
            ->assertJsonPath('branding.landing_theme', 'enterprise');

        $this->withHeader('Origin', 'https://forms.acme.example')
            ->postJson('/api/public/leads', [
                'email' => 'branding-domain@example.test',
                'source' => 'landing_form',
            ])->assertCreated()
            ->assertJsonPath('lead.tenant_id', $tenant->id);
    }

    public function test_set_tenant_resolves_context_from_verified_host_without_authenticated_user(): void
    {
        $tenant = Tenant::factory()->create();

        TenantDomain::factory()->create([
            'tenant_id' => $tenant->id,
            'host' => 'admin.hosted-tenant.example',
            'kind' => TenantDomain::KIND_ADMIN,
            'verification_status' => TenantDomain::VERIFICATION_VERIFIED,
            'verified_at' => now(),
            'ssl_status' => TenantDomain::SSL_ACTIVE,
        ]);

        $this->getJson('http://admin.hosted-tenant.example/_tenant-host-test')
            ->assertOk()
            ->assertJsonPath('tenant_id', $tenant->id);
    }
}
