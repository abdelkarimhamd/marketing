<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\ApiKey;
use App\Models\AssignmentRule;
use App\Models\BillingPlan;
use App\Models\CustomField;
use App\Models\Lead;
use App\Models\LeadForm;
use App\Models\LeadFormField;
use App\Models\Team;
use App\Models\TeamUser;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\TenantSsoConfig;
use App\Models\TenantDomain;
use App\Models\User;
use App\Services\TenantRoleTemplateService;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $demoTenant = Tenant::query()->updateOrCreate(
            ['slug' => 'demo-tenant'],
            [
                'name' => 'Demo Tenant',
                'public_key' => 'trk_demo_tenant_public_key_0001',
                'domain' => 'demo.localhost',
                'settings' => [
                    'compliance' => [
                        'quiet_hours' => [
                            'enabled' => true,
                            'start' => '22:00',
                            'end' => '08:00',
                            'timezone' => 'Asia/Beirut',
                        ],
                        'frequency_caps' => [
                            'email' => 5,
                            'sms' => 3,
                            'whatsapp' => 2,
                        ],
                    ],
                    'retention' => [
                        'messages_months' => 12,
                    ],
                ],
                'branding' => [
                    'logo_url' => 'https://cdn.smartcedra.test/branding/demo-logo.svg',
                    'primary_color' => '#146c94',
                    'secondary_color' => '#0c4f6c',
                    'accent_color' => '#f59e0b',
                    'email_footer' => 'This message was sent by Demo Tenant.',
                    'landing_theme' => 'modern',
                ],
                'timezone' => 'Asia/Beirut',
                'locale' => 'en',
                'currency' => 'USD',
                'sso_required' => false,
                'is_active' => true,
            ],
        );

        User::query()->updateOrCreate([
            'email' => 'super.admin@demo.test',
        ], [
            'tenant_id' => null,
            'name' => 'Super Admin',
            'role' => UserRole::SuperAdmin->value,
            'password' => 'password',
            'is_super_admin' => true,
        ]);

        $tenantAdmin = User::query()->updateOrCreate([
            'email' => 'tenant.admin@demo.test',
        ], [
            'tenant_id' => $demoTenant->id,
            'name' => 'Tenant Admin',
            'role' => UserRole::TenantAdmin->value,
            'password' => 'password',
            'is_super_admin' => false,
        ]);

        User::query()->updateOrCreate([
            'email' => 'sales@demo.test',
        ], [
            'tenant_id' => $demoTenant->id,
            'name' => 'Sales User',
            'role' => UserRole::Sales->value,
            'password' => 'password',
            'is_super_admin' => false,
        ]);

        $managerUser = User::query()->updateOrCreate([
            'email' => 'manager@demo.test',
        ], [
            'tenant_id' => $demoTenant->id,
            'name' => 'Manager User',
            'role' => UserRole::Sales->value,
            'password' => 'password',
            'is_super_admin' => false,
        ]);

        $marketingUser = User::query()->updateOrCreate([
            'email' => 'marketing@demo.test',
        ], [
            'tenant_id' => $demoTenant->id,
            'name' => 'Marketing User',
            'role' => UserRole::Sales->value,
            'password' => 'password',
            'is_super_admin' => false,
        ]);

        $salesUser = User::query()->withoutTenancy()->where('email', 'sales@demo.test')->firstOrFail();

        $templateRoles = app(TenantRoleTemplateService::class)
            ->ensureTenantTemplates($demoTenant->id, $tenantAdmin->id);

        $adminRole = $templateRoles->get('admin');
        $salesRole = $templateRoles->get('sales');
        $managerRole = $templateRoles->get('manager');
        $marketingRole = $templateRoles->get('marketing');

        if ($adminRole !== null) {
            $tenantAdmin->tenantRoles()->syncWithoutDetaching([
                $adminRole->id => ['tenant_id' => $demoTenant->id],
            ]);
        }

        if ($salesRole !== null) {
            $salesUser->tenantRoles()->syncWithoutDetaching([
                $salesRole->id => ['tenant_id' => $demoTenant->id],
            ]);
        }

        if ($managerRole !== null) {
            $managerUser->tenantRoles()->syncWithoutDetaching([
                $managerRole->id => ['tenant_id' => $demoTenant->id],
            ]);
        }

        if ($marketingRole !== null) {
            $marketingUser->tenantRoles()->syncWithoutDetaching([
                $marketingRole->id => ['tenant_id' => $demoTenant->id],
            ]);
        }

        $team = Team::query()->withoutTenancy()->updateOrCreate(
            [
                'tenant_id' => $demoTenant->id,
                'slug' => 'default-sales-team',
            ],
            [
                'name' => 'Default Sales Team',
                'description' => 'Default team for intake auto-assignment.',
                'is_active' => true,
                'settings' => ['timezone' => 'UTC'],
            ]
        );

        TeamUser::query()->withoutTenancy()->updateOrCreate(
            [
                'tenant_id' => $demoTenant->id,
                'team_id' => $team->id,
                'user_id' => $salesUser->id,
            ],
            [
                'role' => 'member',
                'is_primary' => true,
            ]
        );

        AssignmentRule::query()->withoutTenancy()->updateOrCreate(
            [
                'tenant_id' => $demoTenant->id,
                'name' => 'Default Round Robin',
            ],
            [
                'team_id' => $team->id,
                'fallback_owner_id' => $salesUser->id,
                'is_active' => true,
                'priority' => 100,
                'strategy' => AssignmentRule::STRATEGY_ROUND_ROBIN,
                'auto_assign_on_intake' => true,
                'auto_assign_on_import' => true,
                'conditions' => [],
                'settings' => [],
            ]
        );

        ApiKey::query()->withoutTenancy()->updateOrCreate([
            'key_hash' => hash('sha256', 'demo-public-key'),
        ], [
            'tenant_id' => $demoTenant->id,
            'created_by' => $tenantAdmin->id,
            'name' => 'Demo Public Lead Intake',
            'prefix' => 'demo-public-key',
            'secret' => 'demo-public-key',
            'abilities' => ['public:leads:write'],
            'settings' => [
                'channel' => 'public_intake',
            ],
            'revoked_at' => null,
            'expires_at' => null,
        ]);

        TenantDomain::query()->withoutTenancy()->updateOrCreate([
            'host' => 'admin.demo.localhost',
        ], [
            'tenant_id' => $demoTenant->id,
            'kind' => TenantDomain::KIND_ADMIN,
            'is_primary' => true,
            'cname_target' => config('tenancy.cname_targets.admin', config('tenancy.cname_target')),
            'verification_token' => 'seed-admin-demo-token',
            'verification_status' => TenantDomain::VERIFICATION_VERIFIED,
            'verified_at' => now(),
            'verification_error' => null,
            'ssl_status' => TenantDomain::SSL_ACTIVE,
            'ssl_provider' => config('tenancy.ssl.provider', 'local'),
            'ssl_expires_at' => now()->addDays((int) config('tenancy.ssl.default_validity_days', 90)),
            'ssl_last_checked_at' => now(),
            'ssl_error' => null,
            'metadata' => [],
        ]);

        TenantDomain::query()->withoutTenancy()->updateOrCreate([
            'host' => 'demo.localhost',
        ], [
            'tenant_id' => $demoTenant->id,
            'kind' => TenantDomain::KIND_LANDING,
            'is_primary' => true,
            'cname_target' => config('tenancy.cname_targets.landing', config('tenancy.cname_target')),
            'verification_token' => 'seed-landing-demo-token',
            'verification_status' => TenantDomain::VERIFICATION_VERIFIED,
            'verified_at' => now(),
            'verification_error' => null,
            'ssl_status' => TenantDomain::SSL_ACTIVE,
            'ssl_provider' => config('tenancy.ssl.provider', 'local'),
            'ssl_expires_at' => now()->addDays((int) config('tenancy.ssl.default_validity_days', 90)),
            'ssl_last_checked_at' => now(),
            'ssl_error' => null,
            'metadata' => [],
        ]);

        $starterPlan = BillingPlan::query()->updateOrCreate(
            ['slug' => 'starter'],
            [
                'name' => 'Starter',
                'seat_limit' => 5,
                'message_bundle' => 1000,
                'monthly_price' => 49,
                'overage_price_per_message' => 0.02,
                'hard_limit' => false,
                'addons' => [
                    'wa' => false,
                    'sms' => true,
                ],
                'is_active' => true,
            ]
        );

        BillingPlan::query()->updateOrCreate(
            ['slug' => 'growth'],
            [
                'name' => 'Growth',
                'seat_limit' => 25,
                'message_bundle' => 15000,
                'monthly_price' => 249,
                'overage_price_per_message' => 0.015,
                'hard_limit' => false,
                'addons' => [
                    'wa' => true,
                    'sms' => true,
                ],
                'is_active' => true,
            ]
        );

        TenantSubscription::query()->withoutTenancy()->updateOrCreate(
            ['tenant_id' => $demoTenant->id],
            [
                'billing_plan_id' => $starterPlan->id,
                'status' => 'active',
                'current_period_start' => now()->startOfMonth(),
                'current_period_end' => now()->endOfMonth(),
                'provider' => 'manual',
                'metadata' => [
                    'seeded' => true,
                ],
            ]
        );

        $lead = Lead::query()->withoutTenancy()->updateOrCreate(
            [
                'tenant_id' => $demoTenant->id,
                'email' => 'lead.one@demo.test',
            ],
            [
                'first_name' => 'Demo',
                'last_name' => 'Lead',
                'phone' => '+96170000000',
                'city' => 'Beirut',
                'country_code' => 'LB',
                'interest' => 'crm',
                'service' => 'implementation',
                'status' => 'new',
                'source' => 'seed',
                'locale' => 'en',
                'email_consent' => true,
                'consent_updated_at' => now(),
                'settings' => [
                    'consent' => [
                        'email' => true,
                        'sms' => true,
                        'whatsapp' => true,
                    ],
                ],
            ]
        );

        $budgetField = CustomField::query()->withoutTenancy()->updateOrCreate(
            [
                'tenant_id' => $demoTenant->id,
                'entity' => 'lead',
                'slug' => 'budget_range',
            ],
            [
                'name' => 'Budget Range',
                'field_type' => 'select',
                'is_required' => false,
                'is_active' => true,
                'options' => ['choices' => ['<1k', '1k-5k', '5k+']],
            ]
        );

        $form = LeadForm::query()->withoutTenancy()->updateOrCreate(
            [
                'tenant_id' => $demoTenant->id,
                'slug' => 'website-main',
            ],
            [
                'name' => 'Website Main Form',
                'is_active' => true,
                'settings' => [],
            ]
        );

        LeadFormField::query()->withoutTenancy()->updateOrCreate(
            [
                'tenant_id' => $demoTenant->id,
                'lead_form_id' => $form->id,
                'source_key' => 'budget',
            ],
            [
                'custom_field_id' => $budgetField->id,
                'label' => 'Budget',
                'map_to' => 'custom',
                'sort_order' => 1,
                'is_required' => false,
                'validation_rules' => [],
            ]
        );

        TenantSsoConfig::query()->withoutTenancy()->updateOrCreate(
            [
                'tenant_id' => $demoTenant->id,
                'provider' => 'oidc',
            ],
            [
                'settings' => [
                    'issuer' => 'https://login.microsoftonline.com/common/v2.0',
                    'client_id' => 'demo-client-id',
                ],
                'enabled' => false,
                'enforce_sso' => false,
            ]
        );
    }
}
