<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\BillingPlan;
use App\Models\Brand;
use App\Models\Campaign;
use App\Models\CampaignStep;
use App\Models\Lead;
use App\Models\LeadImportPreset;
use App\Models\LeadImportSchedule;
use App\Models\Segment;
use App\Models\Team;
use App\Models\TeamUser;
use App\Models\Template;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Services\TenantRoleTemplateService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RegressionStagingSeeder extends Seeder
{
    private const USERS_PER_TENANT = 10;
    private const LEADS_PER_TENANT = 5000;
    private const BRANDS_TOTAL = 5;

    /**
     * @var list<array<string, string>>
     */
    private const TENANTS = [
        [
            'key' => 'clinic',
            'name' => 'QA Clinic Workspace',
            'slug' => 'qa-clinic',
            'domain' => 'clinic.qa.marketion.local',
            'timezone' => 'Asia/Riyadh',
            'locale' => 'en',
            'currency' => 'SAR',
        ],
        [
            'key' => 'realestate',
            'name' => 'QA RealEstate Workspace',
            'slug' => 'qa-realestate',
            'domain' => 'realestate.qa.marketion.local',
            'timezone' => 'Asia/Riyadh',
            'locale' => 'en',
            'currency' => 'SAR',
        ],
        [
            'key' => 'restaurant',
            'name' => 'QA Restaurant Workspace',
            'slug' => 'qa-restaurant',
            'domain' => 'restaurant.qa.marketion.local',
            'timezone' => 'Asia/Riyadh',
            'locale' => 'ar',
            'currency' => 'SAR',
        ],
    ];

    public function run(): void
    {
        mt_srand(5100);
        fake()->seed(5100);

        $this->ensureGlobalActors();
        $plan = $this->ensureBillingPlan();

        $brandSlots = $this->brandSlots(self::BRANDS_TOTAL, count(self::TENANTS));

        foreach (self::TENANTS as $index => $tenantConfig) {
            $tenant = $this->upsertTenant($tenantConfig);
            $this->upsertSubscription($tenant, $plan);
            $users = $this->upsertUsers($tenant);
            $team = $this->upsertTeam($tenant, $users);
            $this->upsertAssignmentRule($tenant, $team, $users['sales_users'][0] ?? $users['tenant_admin']);
            $brands = $this->upsertBrands($tenant, $brandSlots[$index] ?? 1);
            $segment = $this->upsertSegment($tenant);
            $templates = $this->upsertTemplates($tenant, $brands[0] ?? null);
            $this->upsertCampaigns($tenant, $segment, $templates, $team, $users['tenant_admin']);
            $this->upsertImportArtifacts($tenant, $users['tenant_admin']);
            $this->reseedLeads($tenant, $users['sales_users'], $brands, (string) $tenantConfig['key']);
        }
    }

    private function ensureGlobalActors(): void
    {
        User::query()->withoutTenancy()->updateOrCreate(
            ['email' => 'qa.super.admin@marketion.test'],
            [
                'tenant_id' => null,
                'name' => 'QA Super Admin',
                'role' => UserRole::SuperAdmin->value,
                'password' => 'password',
                'is_super_admin' => true,
                'settings' => [],
                'last_seen_at' => now(),
            ]
        );
    }

    private function ensureBillingPlan(): BillingPlan
    {
        return BillingPlan::query()->updateOrCreate(
            ['slug' => 'qa-growth'],
            [
                'name' => 'QA Growth',
                'seat_limit' => 20,
                'message_bundle' => 50000,
                'monthly_price' => 0,
                'overage_price_per_message' => 0,
                'hard_limit' => false,
                'addons' => ['qa' => true],
                'is_active' => true,
            ]
        );
    }

    /**
     * @param array<string, string> $config
     */
    private function upsertTenant(array $config): Tenant
    {
        return Tenant::query()->withoutTenancy()->updateOrCreate(
            ['slug' => $config['slug']],
            [
                'name' => $config['name'],
                'domain' => $config['domain'],
                'timezone' => $config['timezone'],
                'locale' => $config['locale'],
                'currency' => $config['currency'],
                'is_active' => true,
                'settings' => [
                    'compliance' => [
                        'quiet_hours' => [
                            'enabled' => false,
                            'start' => '22:00',
                            'end' => '08:00',
                            'timezone' => $config['timezone'],
                        ],
                    ],
                    'fatigue' => [
                        'enabled' => true,
                        'max_unengaged_sends' => 5,
                    ],
                    'portal' => [
                        'enabled' => true,
                    ],
                ],
                'branding' => [
                    'landing_theme' => 'modern',
                    'primary_color' => '#146c94',
                ],
            ]
        );
    }

    private function upsertSubscription(Tenant $tenant, BillingPlan $plan): void
    {
        TenantSubscription::query()->withoutTenancy()->updateOrCreate(
            ['tenant_id' => $tenant->id],
            [
                'billing_plan_id' => $plan->id,
                'status' => 'active',
                'seat_limit_override' => 20,
                'message_bundle_override' => 50000,
                'current_period_start' => now()->startOfMonth(),
                'current_period_end' => now()->endOfMonth(),
                'provider' => 'manual',
                'metadata' => ['seed' => 'regression'],
            ]
        );
    }

    /**
     * @return array{tenant_admin: User, sales_users: list<User>}
     */
    private function upsertUsers(Tenant $tenant): array
    {
        $tenantAdmin = User::query()->withoutTenancy()->updateOrCreate(
            ['email' => "qa.admin.{$tenant->slug}@marketion.test"],
            [
                'tenant_id' => $tenant->id,
                'name' => "QA {$tenant->name} Admin",
                'role' => UserRole::TenantAdmin->value,
                'password' => 'password',
                'is_super_admin' => false,
                'settings' => $this->availabilityPayload(dayOffset: 0),
                'last_seen_at' => now(),
            ]
        );

        app(TenantRoleTemplateService::class)->ensureTenantTemplates($tenant->id, $tenantAdmin->id);

        $salesUsers = [];

        for ($i = 1; $i <= self::USERS_PER_TENANT - 1; $i++) {
            $salesUsers[] = User::query()->withoutTenancy()->updateOrCreate(
                ['email' => "qa.sales{$i}.{$tenant->slug}@marketion.test"],
                [
                    'tenant_id' => $tenant->id,
                    'name' => "QA {$tenant->slug} Sales {$i}",
                    'role' => UserRole::Sales->value,
                    'password' => 'password',
                    'is_super_admin' => false,
                    'settings' => $this->availabilityPayload(dayOffset: $i),
                    'last_seen_at' => now(),
                ]
            );
        }

        return [
            'tenant_admin' => $tenantAdmin,
            'sales_users' => $salesUsers,
        ];
    }

    /**
     * @param array{tenant_admin: User, sales_users: list<User>} $users
     */
    private function upsertTeam(Tenant $tenant, array $users): Team
    {
        $team = Team::query()->withoutTenancy()->updateOrCreate(
            [
                'tenant_id' => $tenant->id,
                'slug' => "{$tenant->slug}-sales-team",
            ],
            [
                'name' => "{$tenant->name} Sales Team",
                'description' => 'Regression QA seeded team',
                'is_active' => true,
                'settings' => [
                    'booking_link' => "https://booking.marketion.test/team/{$tenant->slug}",
                ],
            ]
        );

        TeamUser::query()->withoutTenancy()->where('tenant_id', $tenant->id)->where('team_id', $team->id)->delete();

        $allUsers = array_merge([$users['tenant_admin']], $users['sales_users']);

        foreach ($allUsers as $index => $user) {
            TeamUser::query()->withoutTenancy()->create([
                'tenant_id' => $tenant->id,
                'team_id' => $team->id,
                'user_id' => $user->id,
                'role' => $index === 0 ? 'manager' : 'member',
                'is_primary' => $index === 1,
            ]);
        }

        return $team;
    }

    private function upsertAssignmentRule(Tenant $tenant, Team $team, User $fallbackOwner): void
    {
        DB::table('assignment_rules')
            ->where('tenant_id', $tenant->id)
            ->where('name', 'QA Availability + Rules Engine')
            ->delete();

        DB::table('assignment_rules')->insert([
            'tenant_id' => $tenant->id,
            'team_id' => $team->id,
            'last_assigned_user_id' => null,
            'fallback_owner_id' => $fallbackOwner->id,
            'name' => 'QA Availability + Rules Engine',
            'is_active' => true,
            'priority' => 10,
            'strategy' => 'rules_engine',
            'auto_assign_on_intake' => true,
            'auto_assign_on_import' => true,
            'conditions' => json_encode([
                'all' => [
                    ['field' => 'source', 'op' => 'in', 'value' => ['portal', 'whatsapp', 'website']],
                ],
                'actions' => [
                    ['type' => 'assign', 'team_id' => $team->id],
                    ['type' => 'add_tags', 'tags' => ['qa-intake']],
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'settings' => null,
            'last_assigned_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return list<Brand>
     */
    private function upsertBrands(Tenant $tenant, int $count): array
    {
        $brands = [];

        for ($i = 1; $i <= $count; $i++) {
            $slug = "{$tenant->slug}-brand-{$i}";
            $brands[] = Brand::query()->withoutTenancy()->updateOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'slug' => $slug,
                ],
                [
                    'name' => strtoupper($tenant->slug)." Brand {$i}",
                    'is_active' => true,
                    'email_from_address' => "{$slug}@mail.marketion.test",
                    'email_from_name' => strtoupper($tenant->slug)." Brand {$i}",
                    'email_reply_to' => "reply+{$slug}@mail.marketion.test",
                    'sms_sender_id' => strtoupper(Str::substr(preg_replace('/[^a-z0-9]/i', '', $slug), 0, 11)),
                    'whatsapp_phone_number_id' => "wa-{$tenant->id}-{$i}",
                    'landing_domain' => "{$slug}.landing.marketion.test",
                    'landing_page' => ['headline' => "{$tenant->name} Brand {$i}"],
                    'branding' => ['landing_theme' => 'enterprise'],
                    'signatures' => ['email_html' => "<p>Regards,<br>{$slug}</p>"],
                    'settings' => [],
                ]
            );
        }

        return $brands;
    }

    private function upsertSegment(Tenant $tenant): Segment
    {
        return Segment::query()->withoutTenancy()->updateOrCreate(
            [
                'tenant_id' => $tenant->id,
                'slug' => 'qa-all-leads',
            ],
            [
                'name' => 'QA All Leads',
                'description' => 'Regression segment for all leads.',
                'filters' => [],
                'rules_json' => [],
                'settings' => [],
                'is_active' => true,
            ]
        );
    }

    /**
     * @return array<string, Template>
     */
    private function upsertTemplates(Tenant $tenant, ?Brand $brand): array
    {
        $base = [
            'tenant_id' => $tenant->id,
            'brand_id' => $brand?->id,
            'is_active' => true,
        ];

        $email = Template::query()->withoutTenancy()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'slug' => 'qa-email-template'],
            array_merge($base, [
                'name' => 'QA Email Template',
                'channel' => 'email',
                'subject' => 'Hello {{first_name}}',
                'content' => '<p>Hello {{first_name}}, this is a QA campaign.</p>',
                'body_text' => null,
                'whatsapp_template_name' => null,
                'whatsapp_variables' => null,
                'settings' => [],
            ])
        );

        $sms = Template::query()->withoutTenancy()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'slug' => 'qa-sms-template'],
            array_merge($base, [
                'name' => 'QA SMS Template',
                'channel' => 'sms',
                'subject' => null,
                'content' => 'Hi {{first_name}}, QA SMS message.',
                'body_text' => 'Hi {{first_name}}, QA SMS message.',
                'whatsapp_template_name' => null,
                'whatsapp_variables' => null,
                'settings' => [],
            ])
        );

        $whatsapp = Template::query()->withoutTenancy()->updateOrCreate(
            ['tenant_id' => $tenant->id, 'slug' => 'qa-whatsapp-template'],
            array_merge($base, [
                'name' => 'QA WhatsApp Template',
                'channel' => 'whatsapp',
                'subject' => null,
                'content' => '',
                'body_text' => null,
                'whatsapp_template_name' => 'qa_template',
                'whatsapp_variables' => ['first_name' => '{{first_name}}'],
                'settings' => [],
            ])
        );

        return [
            'email' => $email,
            'sms' => $sms,
            'whatsapp' => $whatsapp,
        ];
    }

    /**
     * @param array<string, Template> $templates
     */
    private function upsertCampaigns(
        Tenant $tenant,
        Segment $segment,
        array $templates,
        Team $team,
        User $creator
    ): void {
        $channels = ['email', 'sms', 'whatsapp'];

        foreach ($channels as $index => $channel) {
            /** @var Template $template */
            $template = $templates[$channel];

            $campaign = Campaign::query()->withoutTenancy()->updateOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'slug' => "qa-{$channel}-campaign",
                ],
                [
                    'brand_id' => $template->brand_id,
                    'segment_id' => $segment->id,
                    'template_id' => $template->id,
                    'team_id' => $team->id,
                    'created_by' => $creator->id,
                    'name' => strtoupper($channel).' QA Campaign',
                    'description' => 'Regression campaign seed.',
                    'channel' => $channel,
                    'campaign_type' => $channel === 'email' ? Campaign::TYPE_DRIP : Campaign::TYPE_BROADCAST,
                    'status' => $channel === 'email' ? Campaign::STATUS_SCHEDULED : Campaign::STATUS_DRAFT,
                    'start_at' => now()->addHours($index + 1),
                    'end_at' => null,
                    'launched_at' => null,
                    'settings' => ['seed' => 'regression'],
                    'metrics' => [],
                ]
            );

            if ($campaign->campaign_type === Campaign::TYPE_DRIP) {
                CampaignStep::query()->withoutTenancy()->updateOrCreate(
                    [
                        'tenant_id' => $tenant->id,
                        'campaign_id' => $campaign->id,
                        'step_order' => 1,
                    ],
                    [
                        'template_id' => $template->id,
                        'name' => 'Initial drip step',
                        'channel' => $channel,
                        'delay_minutes' => 0,
                        'is_active' => true,
                        'settings' => [],
                    ]
                );
            }
        }
    }

    private function upsertImportArtifacts(Tenant $tenant, User $actor): void
    {
        $preset = LeadImportPreset::query()->withoutTenancy()->updateOrCreate(
            [
                'tenant_id' => $tenant->id,
                'slug' => 'qa-default-import',
            ],
            [
                'name' => 'QA Default Import Mapping',
                'description' => 'Predictable mapping for regression runs.',
                'mapping' => [
                    'email' => 'email',
                    'phone' => 'phone',
                    'first_name' => 'first_name',
                    'last_name' => 'last_name',
                    'locale' => 'locale',
                ],
                'defaults' => ['source' => 'import', 'status' => 'new'],
                'dedupe_policy' => 'merge',
                'dedupe_keys' => ['email', 'phone'],
                'settings' => ['created_by_seed' => true],
                'is_active' => true,
                'last_used_at' => now(),
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]
        );

        LeadImportSchedule::query()->withoutTenancy()->updateOrCreate(
            [
                'tenant_id' => $tenant->id,
                'name' => 'QA Daily Import',
            ],
            [
                'preset_id' => $preset->id,
                'source_type' => 'url',
                'source_config' => ['url' => "https://imports.marketion.test/{$tenant->slug}.csv"],
                'mapping' => $preset->mapping,
                'defaults' => $preset->defaults,
                'dedupe_policy' => 'merge',
                'dedupe_keys' => ['email', 'phone'],
                'auto_assign' => true,
                'schedule_cron' => '0 * * * *',
                'timezone' => $tenant->timezone,
                'is_active' => true,
                'last_processed_count' => 0,
                'last_status' => null,
                'last_run_at' => null,
                'next_run_at' => now()->addHour(),
                'last_error' => null,
                'settings' => ['source' => 'regression-seed'],
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]
        );
    }

    /**
     * @param list<User> $salesUsers
     * @param list<Brand> $brands
     */
    private function reseedLeads(Tenant $tenant, array $salesUsers, array $brands, string $tenantKey): void
    {
        Lead::query()->withoutTenancy()->where('tenant_id', $tenant->id)->forceDelete();

        $locales = ['en', 'ar'];
        $cities = $this->citiesForTenantKey($tenantKey);
        $sources = ['portal', 'whatsapp', 'website', 'import'];
        $chunk = [];
        $chunkSize = 500;

        for ($index = 1; $index <= self::LEADS_PER_TENANT; $index++) {
            $owner = $salesUsers[$index % max(1, count($salesUsers))] ?? null;
            $brand = $brands[$index % max(1, count($brands))] ?? null;
            $duplicateBucket = $index % 125 === 0 ? (int) floor($index / 125) : null;
            $emailLocal = $duplicateBucket !== null
                ? "dup{$duplicateBucket}"
                : "lead{$index}";

            $phoneSuffix = str_pad((string) (($index % 2500) + 1000), 7, '0', STR_PAD_LEFT);
            $phone = '+9665'.$phoneSuffix;

            $chunk[] = [
                'tenant_id' => $tenant->id,
                'brand_id' => $brand?->id,
                'team_id' => null,
                'owner_id' => $owner?->id,
                'first_name' => "Lead{$index}",
                'last_name' => strtoupper($tenantKey),
                'email' => "{$emailLocal}.{$tenant->slug}@marketion.test",
                'email_consent' => $index % 4 !== 0,
                'consent_updated_at' => now()->subDays($index % 40),
                'phone' => $index % 6 === 0 ? null : $phone,
                'company' => strtoupper($tenantKey)." Co ".(($index % 50) + 1),
                'city' => $cities[$index % count($cities)],
                'country_code' => 'SA',
                'interest' => ['crm', 'ads', 'automation'][$index % 3],
                'service' => ['consulting', 'implementation', 'support'][$index % 3],
                'title' => ['Owner', 'Manager', 'Coordinator'][$index % 3],
                'status' => ['new', 'qualified', 'nurturing'][$index % 3],
                'source' => $sources[$index % count($sources)],
                'score' => $index % 101,
                'timezone' => $tenant->timezone,
                'locale' => $locales[$index % count($locales)],
                'last_contacted_at' => now()->subDays($index % 14),
                'next_follow_up_at' => now()->addDays(($index % 10) + 1),
                'settings' => null,
                'meta' => json_encode([
                    'utm_source' => ['google', 'meta', 'organic'][$index % 3],
                    'utm_campaign' => "qa-campaign-".(($index % 8) + 1),
                    'duplicate_seed_bucket' => $duplicateBucket,
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'created_at' => now()->subDays($index % 120),
                'updated_at' => now()->subDays($index % 30),
                'deleted_at' => null,
            ];

            if (count($chunk) >= $chunkSize) {
                DB::table('leads')->insert($chunk);
                $chunk = [];
            }
        }

        if ($chunk !== []) {
            DB::table('leads')->insert($chunk);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function availabilityPayload(int $dayOffset): array
    {
        $startHour = 8 + ($dayOffset % 3);
        $endHour = 17 + ($dayOffset % 2);

        return [
            'assignment' => [
                'availability' => [
                    'status' => 'available',
                    'is_online' => true,
                    'timezone' => 'Asia/Riyadh',
                    'max_active_leads' => 120 + ($dayOffset * 5),
                    'working_hours' => [
                        'days' => [1, 2, 3, 4, 5],
                        'start' => str_pad((string) $startHour, 2, '0', STR_PAD_LEFT).':00',
                        'end' => str_pad((string) $endHour, 2, '0', STR_PAD_LEFT).':00',
                    ],
                    'holidays' => [],
                ],
            ],
        ];
    }

    /**
     * @return list<int>
     */
    private function brandSlots(int $brandsTotal, int $tenantCount): array
    {
        $slots = array_fill(0, $tenantCount, 1);
        $remaining = max(0, $brandsTotal - $tenantCount);
        $cursor = 0;

        while ($remaining > 0) {
            $slots[$cursor]++;
            $remaining--;
            $cursor = ($cursor + 1) % max(1, $tenantCount);
        }

        return $slots;
    }

    /**
     * @return list<string>
     */
    private function citiesForTenantKey(string $tenantKey): array
    {
        if ($tenantKey === 'clinic') {
            return ['Riyadh', 'Jeddah', 'Dammam'];
        }

        if ($tenantKey === 'realestate') {
            return ['Riyadh', 'Khobar', 'Makkah'];
        }

        return ['Riyadh', 'Jeddah', 'Madinah'];
    }
}
