<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AssignmentRule;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Services\TrackingIngestionService;
use App\Services\TenantEmailConfigurationService;
use App\Services\TenantEncryptionService;
use Illuminate\Support\Arr;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TenantSettingsController extends Controller
{
    /**
     * Return tenant-scoped settings payload for the admin UI.
     */
    public function show(Request $request, TenantEncryptionService $tenantEncryptionService): JsonResponse
    {
        $this->authorizePermission($request, 'settings.view', requireTenantContext: false);

        $tenant = $this->resolveTenantForRequest($request, true);
        app(TrackingIngestionService::class)->ensureTenantPublicKey($tenant);

        $settings = is_array($tenant->settings) ? $tenant->settings : [];
        $branding = is_array($tenant->branding) ? $tenant->branding : [];
        $domains = TenantDomain::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenant->id)
            ->orderByDesc('is_primary')
            ->orderBy('kind')
            ->orderBy('host')
            ->get();

        $legacyDomains = is_array($settings['domains'] ?? null) ? $settings['domains'] : [];
        $mappedDomains = $domains->pluck('host')->all();
        $domainList = array_values(array_unique(array_filter(array_merge($mappedDomains, $legacyDomains))));
        $encryptionMetadata = $tenantEncryptionService->metadataForTenant($tenant);

        return response()->json([
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'public_key' => $tenant->public_key,
                'domain' => $tenant->domain,
                'timezone' => $tenant->timezone,
                'locale' => $tenant->locale,
                'currency' => $tenant->currency,
                'data_residency_region' => $tenant->data_residency_region,
                'data_residency_locked' => (bool) $tenant->data_residency_locked,
                'sso_required' => (bool) $tenant->sso_required,
                'is_active' => $tenant->is_active,
            ],
            'settings' => [
                'providers' => is_array($settings['providers'] ?? null) ? $settings['providers'] : [
                    'email' => config('messaging.providers.email', 'mock'),
                    'sms' => config('messaging.providers.sms', 'mock'),
                    'whatsapp' => config('messaging.providers.whatsapp', 'mock'),
                ],
                'domains' => $domainList,
                'custom_domains' => $domains,
                'slack' => is_array($settings['slack'] ?? null) ? $settings['slack'] : [],
                'branding' => $branding,
                'compliance' => is_array($settings['compliance'] ?? null) ? $settings['compliance'] : [],
                'portal' => $this->presentPortalSettings($settings),
                'bot' => $this->presentBotSettings($settings),
                'cost_engine' => $this->presentCostEngineSettings($settings),
                'encryption' => $encryptionMetadata,
                'email_delivery' => $this->presentEmailDeliverySettings($settings),
                'rules' => AssignmentRule::query()
                    ->where('tenant_id', $tenant->id)
                    ->orderBy('priority')
                    ->get(),
            ],
        ]);
    }

    /**
     * Update tenant-scoped settings payload.
     */
    public function update(Request $request, TenantEncryptionService $tenantEncryptionService): JsonResponse
    {
        $this->authorizePermission($request, 'settings.update', requireTenantContext: false);

        $tenant = $this->resolveTenantForRequest($request, false);
        app(TrackingIngestionService::class)->ensureTenantPublicKey($tenant);

        $payload = $request->validate([
            'domain' => ['nullable', 'string', 'max:255'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'locale' => ['nullable', 'string', 'max:12'],
            'currency' => ['nullable', 'string', 'max:8'],
            'data_residency_region' => ['nullable', Rule::in($this->allowedResidencyRegions())],
            'data_residency_locked' => ['nullable', 'boolean'],
            'sso_required' => ['nullable', 'boolean'],
            'providers' => ['nullable', 'array'],
            'providers.email' => ['nullable', 'string', 'max:120'],
            'providers.sms' => ['nullable', 'string', 'max:120'],
            'providers.whatsapp' => ['nullable', 'string', 'max:120'],
            'domains' => ['nullable', 'array'],
            'domains.*' => ['string', 'max:255'],
            'slack' => ['nullable', 'array'],
            'slack.webhook_url' => ['nullable', 'string', 'max:1000'],
            'slack.channel' => ['nullable', 'string', 'max:255'],
            'slack.enabled' => ['nullable', 'boolean'],
            'compliance' => ['nullable', 'array'],
            'portal' => ['nullable', 'array'],
            'portal.enabled' => ['nullable', 'boolean'],
            'portal.headline' => ['nullable', 'string', 'max:255'],
            'portal.subtitle' => ['nullable', 'string', 'max:2000'],
            'portal.support_email' => ['nullable', 'email', 'max:255'],
            'portal.support_phone' => ['nullable', 'string', 'max:64'],
            'portal.privacy_url' => ['nullable', 'url', 'max:2000'],
            'portal.terms_url' => ['nullable', 'url', 'max:2000'],
            'portal.source_prefix' => ['nullable', 'string', 'max:50'],
            'portal.default_status' => ['nullable', 'string', 'max:50'],
            'portal.auto_assign' => ['nullable', 'boolean'],
            'portal.default_form_slug' => ['nullable', 'string', 'max:120'],
            'portal.default_tags' => ['nullable', 'array', 'max:25'],
            'portal.default_tags.*' => ['string', 'max:80'],
            'portal.tracking_token_ttl_days' => ['nullable', 'integer', 'min:1', 'max:3650'],
            'portal.features' => ['nullable', 'array'],
            'portal.features.request_quote' => ['nullable', 'boolean'],
            'portal.features.book_demo' => ['nullable', 'boolean'],
            'portal.features.upload_docs' => ['nullable', 'boolean'],
            'portal.features.track_status' => ['nullable', 'boolean'],
            'portal.booking' => ['nullable', 'array'],
            'portal.booking.default_timezone' => ['nullable', 'string', 'max:64'],
            'portal.booking.allowed_channels' => ['nullable', 'array', 'max:20'],
            'portal.booking.allowed_channels.*' => ['string', 'max:50'],
            'portal.booking.default_duration_minutes' => ['nullable', 'integer', 'min:5', 'max:720'],
            'portal.booking.deal_stage_on_booking' => ['nullable', 'string', 'max:80'],
            'portal.booking.default_link' => ['nullable', 'url', 'max:2000'],
            'bot' => ['nullable', 'array'],
            'bot.enabled' => ['nullable', 'boolean'],
            'bot.channels' => ['nullable', 'array'],
            'bot.channels.whatsapp' => ['nullable', 'boolean'],
            'bot.channels.website_chat' => ['nullable', 'boolean'],
            'bot.welcome_message' => ['nullable', 'string', 'max:2000'],
            'bot.default_reply' => ['nullable', 'string', 'max:2000'],
            'bot.handoff_reply' => ['nullable', 'string', 'max:2000'],
            'bot.completion_reply' => ['nullable', 'string', 'max:2000'],
            'bot.handoff_keywords' => ['nullable', 'array', 'max:30'],
            'bot.handoff_keywords.*' => ['string', 'max:80'],
            'bot.qualification' => ['nullable', 'array'],
            'bot.qualification.enabled' => ['nullable', 'boolean'],
            'bot.qualification.auto_qualify' => ['nullable', 'boolean'],
            'bot.qualification.questions' => ['nullable', 'array', 'max:25'],
            'bot.qualification.questions.*.key' => ['required_with:bot.qualification.questions', 'string', 'max:80'],
            'bot.qualification.questions.*.question' => ['required_with:bot.qualification.questions', 'string', 'max:500'],
            'bot.qualification.questions.*.field' => ['nullable', 'string', 'max:80'],
            'bot.qualification.questions.*.required' => ['nullable', 'boolean'],
            'bot.faq' => ['nullable', 'array', 'max:100'],
            'bot.faq.*.question' => ['nullable', 'string', 'max:255'],
            'bot.faq.*.keywords' => ['nullable', 'array', 'max:20'],
            'bot.faq.*.keywords.*' => ['string', 'max:80'],
            'bot.faq.*.answer' => ['required_with:bot.faq', 'string', 'max:2000'],
            'bot.appointment' => ['nullable', 'array'],
            'bot.appointment.enabled' => ['nullable', 'boolean'],
            'bot.appointment.keywords' => ['nullable', 'array', 'max:20'],
            'bot.appointment.keywords.*' => ['string', 'max:80'],
            'bot.appointment.booking_url' => ['nullable', 'url', 'max:2000'],
            'bot.appointment.reply' => ['nullable', 'string', 'max:2000'],
            'bot.whatsapp' => ['nullable', 'array'],
            'bot.whatsapp.phone_number_id' => ['nullable', 'string', 'max:120'],
            'cost_engine' => ['nullable', 'array'],
            'cost_engine.provider_costs' => ['nullable', 'array'],
            'cost_engine.provider_costs.email' => ['nullable', 'array'],
            'cost_engine.provider_costs.email.*' => ['nullable', 'numeric', 'min:0'],
            'cost_engine.provider_costs.sms' => ['nullable', 'array'],
            'cost_engine.provider_costs.sms.*' => ['nullable', 'numeric', 'min:0'],
            'cost_engine.provider_costs.whatsapp' => ['nullable', 'array'],
            'cost_engine.provider_costs.whatsapp.*' => ['nullable', 'numeric', 'min:0'],
            'cost_engine.overhead_per_message' => ['nullable', 'array'],
            'cost_engine.overhead_per_message.email' => ['nullable', 'numeric', 'min:0'],
            'cost_engine.overhead_per_message.sms' => ['nullable', 'numeric', 'min:0'],
            'cost_engine.overhead_per_message.whatsapp' => ['nullable', 'numeric', 'min:0'],
            'cost_engine.revenue_per_message' => ['nullable', 'array'],
            'cost_engine.revenue_per_message.email' => ['nullable', 'numeric', 'min:0'],
            'cost_engine.revenue_per_message.sms' => ['nullable', 'numeric', 'min:0'],
            'cost_engine.revenue_per_message.whatsapp' => ['nullable', 'numeric', 'min:0'],
            'cost_engine.margin_alert_threshold_percent' => ['nullable', 'numeric', 'min:-100', 'max:100'],
            'cost_engine.margin_alert_min_messages' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'email_delivery' => ['nullable', 'array'],
            'email_delivery.mode' => ['nullable', Rule::in([
                TenantEmailConfigurationService::MODE_PLATFORM,
                TenantEmailConfigurationService::MODE_TENANT,
            ])],
            'email_delivery.from_address' => ['nullable', 'email', 'max:255'],
            'email_delivery.from_name' => ['nullable', 'string', 'max:255'],
            'email_delivery.use_custom_smtp' => ['nullable', 'boolean'],
            'email_delivery.smtp_host' => ['nullable', 'string', 'max:255'],
            'email_delivery.smtp_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'email_delivery.smtp_username' => ['nullable', 'string', 'max:255'],
            'email_delivery.smtp_password' => ['nullable', 'string', 'max:255'],
            'email_delivery.smtp_encryption' => ['nullable', Rule::in(['tls', 'ssl'])],
            'branding' => ['nullable', 'array'],
            'branding.logo_url' => ['nullable', 'string', 'max:2000'],
            'branding.primary_color' => ['nullable', 'regex:/^#(?:[0-9a-fA-F]{3}){1,2}$/'],
            'branding.secondary_color' => ['nullable', 'regex:/^#(?:[0-9a-fA-F]{3}){1,2}$/'],
            'branding.accent_color' => ['nullable', 'regex:/^#(?:[0-9a-fA-F]{3}){1,2}$/'],
            'branding.email_footer' => ['nullable', 'string', 'max:5000'],
            'branding.landing_theme' => ['nullable', Rule::in([
                'default',
                'modern',
                'minimal',
                'enterprise',
            ])],
        ]);

        $settings = is_array($tenant->settings) ? $tenant->settings : [];
        $branding = is_array($tenant->branding) ? $tenant->branding : [];

        if (array_key_exists('providers', $payload) && is_array($payload['providers'])) {
            $settings['providers'] = array_merge(
                is_array($settings['providers'] ?? null) ? $settings['providers'] : [],
                $payload['providers']
            );
        }

        if (array_key_exists('domains', $payload) && is_array($payload['domains'])) {
            $settings['domains'] = array_values(array_unique(array_map(
                static fn (string $domain): string => trim($domain),
                array_filter($payload['domains'], static fn ($value): bool => trim((string) $value) !== '')
            )));
        }

        if (array_key_exists('slack', $payload) && is_array($payload['slack'])) {
            $settings['slack'] = array_merge(
                is_array($settings['slack'] ?? null) ? $settings['slack'] : [],
                $payload['slack']
            );
        }

        if (array_key_exists('compliance', $payload) && is_array($payload['compliance'])) {
            $settings['compliance'] = array_merge(
                is_array($settings['compliance'] ?? null) ? $settings['compliance'] : [],
                $payload['compliance']
            );
        }

        if (array_key_exists('portal', $payload) && is_array($payload['portal'])) {
            $settings['portal'] = $this->mergePortalSettings($settings, $payload['portal']);
        }

        if (array_key_exists('bot', $payload) && is_array($payload['bot'])) {
            $settings['bot'] = $this->mergeBotSettings($settings, $payload['bot']);
        }

        if (array_key_exists('cost_engine', $payload) && is_array($payload['cost_engine'])) {
            $settings['cost_engine'] = $this->mergeCostEngineSettings($settings, $payload['cost_engine']);
        }

        if (array_key_exists('email_delivery', $payload) && is_array($payload['email_delivery'])) {
            $settings['email_delivery'] = $this->mergeEmailDeliverySettings(
                $tenant,
                $settings,
                $payload['email_delivery'],
                $tenantEncryptionService
            );
        }

        if (array_key_exists('branding', $payload) && is_array($payload['branding'])) {
            $branding = array_merge($branding, $payload['branding']);
        }

        if (
            array_key_exists('data_residency_region', $payload)
            && (bool) $tenant->data_residency_locked
            && $this->normalizeDataResidencyRegion($payload['data_residency_region'] ?? null) !== $tenant->data_residency_region
        ) {
            abort(422, 'Data residency region is locked for this tenant.');
        }

        $tenant->forceFill([
            'domain' => array_key_exists('domain', $payload) ? $payload['domain'] : $tenant->domain,
            'timezone' => array_key_exists('timezone', $payload) ? $payload['timezone'] : $tenant->timezone,
            'locale' => array_key_exists('locale', $payload) ? $payload['locale'] : $tenant->locale,
            'currency' => array_key_exists('currency', $payload) ? $payload['currency'] : $tenant->currency,
            'data_residency_region' => array_key_exists('data_residency_region', $payload)
                ? $this->normalizeDataResidencyRegion($payload['data_residency_region'] ?? null)
                : $tenant->data_residency_region,
            'data_residency_locked' => array_key_exists('data_residency_locked', $payload)
                ? (bool) $payload['data_residency_locked']
                : (bool) $tenant->data_residency_locked,
            'sso_required' => array_key_exists('sso_required', $payload) ? (bool) $payload['sso_required'] : $tenant->sso_required,
            'settings' => $settings,
            'branding' => $branding,
        ])->save();

        return response()->json([
            'message' => 'Tenant settings updated.',
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'public_key' => $tenant->public_key,
                'domain' => $tenant->domain,
                'timezone' => $tenant->timezone,
                'locale' => $tenant->locale,
                'currency' => $tenant->currency,
                'data_residency_region' => $tenant->data_residency_region,
                'data_residency_locked' => (bool) $tenant->data_residency_locked,
                'sso_required' => (bool) $tenant->sso_required,
                'is_active' => $tenant->is_active,
            ],
            'settings' => [
                ...$settings,
                'portal' => $this->presentPortalSettings($settings),
                'bot' => $this->presentBotSettings($settings),
                'cost_engine' => $this->presentCostEngineSettings($settings),
                'encryption' => $tenantEncryptionService->metadataForTenant($tenant),
                'email_delivery' => $this->presentEmailDeliverySettings($settings),
            ],
            'branding' => $branding,
        ]);
    }

    /**
     * Rotate tenant encryption key and re-encrypt sensitive settings.
     */
    public function rotateEncryptionKey(
        Request $request,
        TenantEncryptionService $tenantEncryptionService
    ): JsonResponse {
        $this->authorizePermission($request, 'settings.update', requireTenantContext: false);
        $tenant = $this->resolveTenantForRequest($request, false);

        $payload = $request->validate([
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $result = $tenantEncryptionService->rotateKey(
            tenant: $tenant,
            actorUserId: $request->user()?->id,
            reason: $payload['reason'] ?? null,
        );

        return response()->json([
            'message' => 'Tenant encryption key rotated.',
            'encryption' => $tenantEncryptionService->metadataForTenant($tenant->refresh()),
            'rotation' => $result,
        ]);
    }

    /**
     * Present email delivery settings payload without leaking stored password.
     *
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function presentEmailDeliverySettings(array $settings): array
    {
        $stored = is_array($settings['email_delivery'] ?? null) ? $settings['email_delivery'] : [];
        $mode = mb_strtolower((string) ($stored['mode'] ?? TenantEmailConfigurationService::MODE_PLATFORM));

        if (! in_array($mode, [
            TenantEmailConfigurationService::MODE_PLATFORM,
            TenantEmailConfigurationService::MODE_TENANT,
        ], true)) {
            $mode = TenantEmailConfigurationService::MODE_PLATFORM;
        }

        $encryption = is_string($stored['smtp_encryption'] ?? null)
            ? mb_strtolower(trim((string) $stored['smtp_encryption']))
            : null;

        return [
            'mode' => $mode,
            'from_address' => $stored['from_address'] ?? null,
            'from_name' => $stored['from_name'] ?? null,
            'use_custom_smtp' => (bool) ($stored['use_custom_smtp'] ?? false),
            'smtp_host' => $stored['smtp_host'] ?? null,
            'smtp_port' => $stored['smtp_port'] ?? null,
            'smtp_username' => $stored['smtp_username'] ?? null,
            'smtp_encryption' => in_array($encryption, ['tls', 'ssl'], true) ? $encryption : null,
            'has_smtp_password' => is_string($stored['smtp_password_encrypted'] ?? null)
                && trim((string) $stored['smtp_password_encrypted']) !== '',
        ];
    }

    /**
     * Merge and normalize incoming email-delivery settings.
     *
     * @param Tenant $tenant
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $incoming
     * @param TenantEncryptionService $tenantEncryptionService
     * @return array<string, mixed>
     */
    private function mergeEmailDeliverySettings(
        Tenant $tenant,
        array $settings,
        array $incoming,
        TenantEncryptionService $tenantEncryptionService
    ): array
    {
        $existing = is_array($settings['email_delivery'] ?? null) ? $settings['email_delivery'] : [];
        $merged = array_merge(
            $existing,
            Arr::only($incoming, [
                'mode',
                'from_address',
                'from_name',
                'use_custom_smtp',
                'smtp_host',
                'smtp_port',
                'smtp_username',
                'smtp_encryption',
            ])
        );

        $mode = mb_strtolower((string) ($merged['mode'] ?? TenantEmailConfigurationService::MODE_PLATFORM));

        if (! in_array($mode, [
            TenantEmailConfigurationService::MODE_PLATFORM,
            TenantEmailConfigurationService::MODE_TENANT,
        ], true)) {
            $mode = TenantEmailConfigurationService::MODE_PLATFORM;
        }

        $merged['mode'] = $mode;
        $merged['use_custom_smtp'] = (bool) ($merged['use_custom_smtp'] ?? false);

        foreach (['from_address', 'from_name', 'smtp_host', 'smtp_username'] as $key) {
            if (! array_key_exists($key, $merged)) {
                continue;
            }

            $value = is_string($merged[$key]) ? trim($merged[$key]) : '';
            $merged[$key] = $value !== '' ? $value : null;
        }

        if (array_key_exists('smtp_port', $merged) && $merged['smtp_port'] !== null) {
            $merged['smtp_port'] = (int) $merged['smtp_port'];
        }

        $encryption = is_string($merged['smtp_encryption'] ?? null)
            ? mb_strtolower(trim((string) $merged['smtp_encryption']))
            : null;
        $merged['smtp_encryption'] = in_array($encryption, ['tls', 'ssl'], true) ? $encryption : null;

        if (array_key_exists('smtp_password', $incoming)) {
            $password = is_string($incoming['smtp_password']) ? trim($incoming['smtp_password']) : '';

            if ($password === '') {
                unset($merged['smtp_password_encrypted']);
            } else {
                $merged['smtp_password_encrypted'] = $tenantEncryptionService->encryptForTenant($tenant, $password);
            }
        }

        unset($merged['smtp_password']);

        return $merged;
    }

    /**
     * Present portal settings merged with platform defaults.
     *
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function presentPortalSettings(array $settings): array
    {
        $defaults = $this->normalizePortalSettings([
            'enabled' => config('portal.enabled', true),
            'headline' => config('portal.headline', 'Talk to our team'),
            'subtitle' => config('portal.subtitle', ''),
            'source_prefix' => config('portal.source_prefix', 'portal'),
            'default_status' => config('portal.default_status', 'new'),
            'auto_assign' => config('portal.auto_assign', true),
            'default_tags' => config('portal.default_tags', ['portal']),
            'tracking_token_ttl_days' => config('portal.tracking_token_ttl_days', 180),
            'features' => config('portal.features', []),
            'booking' => config('portal.booking', []),
        ]);

        $stored = $this->normalizePortalSettings(
            is_array($settings['portal'] ?? null) ? $settings['portal'] : []
        );

        return [
            'enabled' => (bool) (
                $stored['enabled']
                ?? $defaults['enabled']
                ?? true
            ),
            'headline' => (string) (
                $stored['headline']
                ?? $defaults['headline']
                ?? 'Talk to our team'
            ),
            'subtitle' => (string) (
                $stored['subtitle']
                ?? $defaults['subtitle']
                ?? ''
            ),
            'support_email' => $stored['support_email'] ?? null,
            'support_phone' => $stored['support_phone'] ?? null,
            'privacy_url' => $stored['privacy_url'] ?? null,
            'terms_url' => $stored['terms_url'] ?? null,
            'source_prefix' => (string) (
                $stored['source_prefix']
                ?? $defaults['source_prefix']
                ?? 'portal'
            ),
            'default_status' => (string) (
                $stored['default_status']
                ?? $defaults['default_status']
                ?? 'new'
            ),
            'auto_assign' => (bool) (
                $stored['auto_assign']
                ?? $defaults['auto_assign']
                ?? true
            ),
            'default_form_slug' => $stored['default_form_slug'] ?? null,
            'default_tags' => $stored['default_tags']
                ?? $defaults['default_tags']
                ?? ['portal'],
            'tracking_token_ttl_days' => (int) (
                $stored['tracking_token_ttl_days']
                ?? $defaults['tracking_token_ttl_days']
                ?? 180
            ),
            'features' => [
                'request_quote' => (bool) (
                    data_get($stored, 'features.request_quote')
                    ?? data_get($defaults, 'features.request_quote')
                    ?? true
                ),
                'book_demo' => (bool) (
                    data_get($stored, 'features.book_demo')
                    ?? data_get($defaults, 'features.book_demo')
                    ?? true
                ),
                'upload_docs' => (bool) (
                    data_get($stored, 'features.upload_docs')
                    ?? data_get($defaults, 'features.upload_docs')
                    ?? true
                ),
                'track_status' => (bool) (
                    data_get($stored, 'features.track_status')
                    ?? data_get($defaults, 'features.track_status')
                    ?? true
                ),
            ],
            'booking' => [
                'default_timezone' => (string) (
                    data_get($stored, 'booking.default_timezone')
                    ?? data_get($defaults, 'booking.default_timezone')
                    ?? config('app.timezone', 'UTC')
                ),
                'allowed_channels' => data_get($stored, 'booking.allowed_channels')
                    ?? data_get($defaults, 'booking.allowed_channels')
                    ?? ['video', 'phone', 'in_person'],
                'default_duration_minutes' => (int) (
                    data_get($stored, 'booking.default_duration_minutes')
                    ?? data_get($defaults, 'booking.default_duration_minutes')
                    ?? 30
                ),
                'deal_stage_on_booking' => (string) (
                    data_get($stored, 'booking.deal_stage_on_booking')
                    ?? data_get($defaults, 'booking.deal_stage_on_booking')
                    ?? 'demo_booked'
                ),
                'default_link' => data_get($stored, 'booking.default_link')
                    ?? data_get($defaults, 'booking.default_link'),
            ],
        ];
    }

    /**
     * Merge incoming portal settings with existing tenant overrides.
     *
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $incoming
     * @return array<string, mixed>
     */
    private function mergePortalSettings(array $settings, array $incoming): array
    {
        $merged = $this->normalizePortalSettings(
            is_array($settings['portal'] ?? null) ? $settings['portal'] : []
        );

        foreach ([
            'enabled',
            'headline',
            'subtitle',
            'support_email',
            'support_phone',
            'privacy_url',
            'terms_url',
            'source_prefix',
            'default_status',
            'auto_assign',
            'default_form_slug',
            'tracking_token_ttl_days',
        ] as $key) {
            if (! array_key_exists($key, $incoming)) {
                continue;
            }

            $value = $incoming[$key];

            if ($value === null || (is_string($value) && trim($value) === '')) {
                unset($merged[$key]);
                continue;
            }

            $merged[$key] = $value;
        }

        if (array_key_exists('default_tags', $incoming) && is_array($incoming['default_tags'])) {
            $merged['default_tags'] = array_values(array_unique(array_filter(array_map(
                static fn (mixed $tag): string => trim((string) $tag),
                $incoming['default_tags']
            ))));
        }

        if (array_key_exists('features', $incoming) && is_array($incoming['features'])) {
            $merged['features'] = array_merge(
                is_array($merged['features'] ?? null) ? $merged['features'] : [],
                $incoming['features']
            );
        }

        if (array_key_exists('booking', $incoming) && is_array($incoming['booking'])) {
            $merged['booking'] = array_merge(
                is_array($merged['booking'] ?? null) ? $merged['booking'] : [],
                $incoming['booking']
            );
        }

        return $this->normalizePortalSettings($merged);
    }

    /**
     * Normalize and constrain portal settings structure.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalizePortalSettings(array $payload): array
    {
        $normalized = [];

        if (array_key_exists('enabled', $payload)) {
            $normalized['enabled'] = (bool) $payload['enabled'];
        }

        foreach ([
            'headline',
            'subtitle',
            'support_email',
            'support_phone',
            'privacy_url',
            'terms_url',
            'source_prefix',
            'default_status',
            'default_form_slug',
        ] as $key) {
            if (! is_string($payload[$key] ?? null)) {
                continue;
            }

            $value = trim((string) $payload[$key]);

            if ($value !== '') {
                $normalized[$key] = $value;
            }
        }

        if (array_key_exists('auto_assign', $payload)) {
            $normalized['auto_assign'] = (bool) $payload['auto_assign'];
        }

        if (is_numeric($payload['tracking_token_ttl_days'] ?? null)) {
            $normalized['tracking_token_ttl_days'] = max(1, min(3650, (int) $payload['tracking_token_ttl_days']));
        }

        if (is_array($payload['default_tags'] ?? null)) {
            $normalized['default_tags'] = array_values(array_unique(array_filter(array_map(
                static fn (mixed $tag): string => trim((string) $tag),
                $payload['default_tags']
            ))));
        }

        if (is_array($payload['features'] ?? null)) {
            $normalized['features'] = [
                'request_quote' => array_key_exists('request_quote', $payload['features'])
                    ? (bool) $payload['features']['request_quote']
                    : true,
                'book_demo' => array_key_exists('book_demo', $payload['features'])
                    ? (bool) $payload['features']['book_demo']
                    : true,
                'upload_docs' => array_key_exists('upload_docs', $payload['features'])
                    ? (bool) $payload['features']['upload_docs']
                    : true,
                'track_status' => array_key_exists('track_status', $payload['features'])
                    ? (bool) $payload['features']['track_status']
                    : true,
            ];
        }

        if (is_array($payload['booking'] ?? null)) {
            $booking = [];

            if (is_string($payload['booking']['default_timezone'] ?? null)) {
                $timezone = trim((string) $payload['booking']['default_timezone']);

                if ($timezone !== '') {
                    $booking['default_timezone'] = $timezone;
                }
            }

            if (is_array($payload['booking']['allowed_channels'] ?? null)) {
                $booking['allowed_channels'] = array_values(array_unique(array_filter(array_map(
                    static fn (mixed $channel): string => trim(mb_strtolower((string) $channel)),
                    $payload['booking']['allowed_channels']
                ))));
            }

            if (is_numeric($payload['booking']['default_duration_minutes'] ?? null)) {
                $booking['default_duration_minutes'] = max(
                    5,
                    min(720, (int) $payload['booking']['default_duration_minutes'])
                );
            }

            if (is_string($payload['booking']['deal_stage_on_booking'] ?? null)) {
                $status = trim((string) $payload['booking']['deal_stage_on_booking']);

                if ($status !== '') {
                    $booking['deal_stage_on_booking'] = $status;
                }
            }

            if (is_string($payload['booking']['default_link'] ?? null)) {
                $defaultLink = trim((string) $payload['booking']['default_link']);
                $booking['default_link'] = $defaultLink !== '' ? $defaultLink : null;
            }

            if ($booking !== []) {
                $normalized['booking'] = $booking;
            }
        }

        return $normalized;
    }

    /**
     * Present bot settings merged with platform defaults.
     *
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function presentBotSettings(array $settings): array
    {
        $defaults = $this->normalizeBotSettings(
            is_array(config('bot')) ? config('bot') : []
        );
        $rawStored = is_array($settings['bot'] ?? null) ? $settings['bot'] : [];
        $stored = $this->normalizeBotSettings(
            $rawStored
        );

        $merged = array_replace_recursive($defaults, $stored);

        if (array_key_exists('handoff_keywords', $rawStored)) {
            $merged['handoff_keywords'] = $stored['handoff_keywords'] ?? [];
        }

        if (array_key_exists('faq', $rawStored)) {
            $merged['faq'] = $stored['faq'] ?? [];
        }

        if (is_array($rawStored['qualification'] ?? null) && array_key_exists('questions', $rawStored['qualification'])) {
            data_set($merged, 'qualification.questions', data_get($stored, 'qualification.questions', []));
        }

        if (is_array($rawStored['appointment'] ?? null) && array_key_exists('keywords', $rawStored['appointment'])) {
            data_set($merged, 'appointment.keywords', data_get($stored, 'appointment.keywords', []));
        }

        return $this->normalizeBotSettings($merged);
    }

    /**
     * Merge incoming bot settings with existing tenant overrides.
     *
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $incoming
     * @return array<string, mixed>
     */
    private function mergeBotSettings(array $settings, array $incoming): array
    {
        $existing = $this->normalizeBotSettings(
            is_array($settings['bot'] ?? null) ? $settings['bot'] : []
        );
        $normalizedIncoming = $this->normalizeBotSettings($incoming);
        $merged = array_replace_recursive($existing, $normalizedIncoming);

        if (array_key_exists('handoff_keywords', $incoming)) {
            $merged['handoff_keywords'] = $normalizedIncoming['handoff_keywords'] ?? [];
        }

        if (array_key_exists('faq', $incoming)) {
            $merged['faq'] = $normalizedIncoming['faq'] ?? [];
        }

        if (is_array($incoming['qualification'] ?? null) && array_key_exists('questions', $incoming['qualification'])) {
            data_set($merged, 'qualification.questions', data_get($normalizedIncoming, 'qualification.questions', []));
        }

        if (is_array($incoming['appointment'] ?? null) && array_key_exists('keywords', $incoming['appointment'])) {
            data_set($merged, 'appointment.keywords', data_get($normalizedIncoming, 'appointment.keywords', []));
        }

        return $this->normalizeBotSettings($merged);
    }

    /**
     * Normalize and constrain bot settings structure.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalizeBotSettings(array $payload): array
    {
        $normalized = [
            'enabled' => (bool) ($payload['enabled'] ?? true),
            'channels' => [
                'whatsapp' => (bool) data_get($payload, 'channels.whatsapp', true),
                'website_chat' => (bool) data_get($payload, 'channels.website_chat', true),
            ],
            'welcome_message' => trim((string) ($payload['welcome_message'] ?? '')),
            'default_reply' => trim((string) ($payload['default_reply'] ?? '')),
            'handoff_reply' => trim((string) ($payload['handoff_reply'] ?? '')),
            'completion_reply' => trim((string) ($payload['completion_reply'] ?? '')),
            'handoff_keywords' => [],
            'qualification' => [
                'enabled' => (bool) data_get($payload, 'qualification.enabled', true),
                'auto_qualify' => (bool) data_get($payload, 'qualification.auto_qualify', true),
                'questions' => [],
            ],
            'faq' => [],
            'appointment' => [
                'enabled' => (bool) data_get($payload, 'appointment.enabled', true),
                'keywords' => [],
                'booking_url' => null,
                'reply' => trim((string) data_get($payload, 'appointment.reply', '')),
            ],
            'whatsapp' => [
                'phone_number_id' => null,
            ],
        ];

        if (is_array($payload['handoff_keywords'] ?? null)) {
            $normalized['handoff_keywords'] = array_values(array_unique(array_filter(array_map(
                static fn (mixed $keyword): string => trim(mb_strtolower((string) $keyword)),
                $payload['handoff_keywords']
            ))));
        }

        if (is_array($payload['appointment']['keywords'] ?? null)) {
            $normalized['appointment']['keywords'] = array_values(array_unique(array_filter(array_map(
                static fn (mixed $keyword): string => trim(mb_strtolower((string) $keyword)),
                $payload['appointment']['keywords']
            ))));
        }

        if (is_string($payload['appointment']['booking_url'] ?? null)) {
            $bookingUrl = trim((string) $payload['appointment']['booking_url']);
            $normalized['appointment']['booking_url'] = $bookingUrl !== '' ? $bookingUrl : null;
        }

        if (is_string($payload['whatsapp']['phone_number_id'] ?? null)) {
            $phoneNumberId = trim((string) $payload['whatsapp']['phone_number_id']);
            $normalized['whatsapp']['phone_number_id'] = $phoneNumberId !== '' ? $phoneNumberId : null;
        }

        if (is_array($payload['qualification']['questions'] ?? null)) {
            foreach ($payload['qualification']['questions'] as $question) {
                if (! is_array($question)) {
                    continue;
                }

                $key = trim((string) ($question['key'] ?? ''));
                $questionText = trim((string) ($question['question'] ?? ''));
                $field = trim((string) ($question['field'] ?? ''));

                if ($key === '' || $questionText === '') {
                    continue;
                }

                $normalized['qualification']['questions'][] = [
                    'key' => $key,
                    'question' => $questionText,
                    'field' => $field !== '' ? $field : $key,
                    'required' => array_key_exists('required', $question)
                        ? (bool) $question['required']
                        : true,
                ];
            }
        }

        if (is_array($payload['faq'] ?? null)) {
            foreach ($payload['faq'] as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $answer = trim((string) ($entry['answer'] ?? ''));

                if ($answer === '') {
                    continue;
                }

                $keywords = [];

                if (is_array($entry['keywords'] ?? null)) {
                    $keywords = array_values(array_unique(array_filter(array_map(
                        static fn (mixed $keyword): string => trim(mb_strtolower((string) $keyword)),
                        $entry['keywords']
                    ))));
                }

                $normalized['faq'][] = [
                    'question' => trim((string) ($entry['question'] ?? '')),
                    'keywords' => $keywords,
                    'answer' => $answer,
                ];
            }
        }

        return $normalized;
    }

    /**
     * Present cost-engine settings merged with platform defaults.
     *
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function presentCostEngineSettings(array $settings): array
    {
        $defaults = $this->normalizeCostEngineSettings([
            'provider_costs' => config('cost_engine.provider_costs', []),
            'overhead_per_message' => config('cost_engine.overhead_per_message', []),
            'revenue_per_message' => config('cost_engine.revenue_per_message', []),
            'margin_alert_threshold_percent' => config('cost_engine.margin_alert_threshold_percent', 15),
            'margin_alert_min_messages' => config('cost_engine.margin_alert_min_messages', 10),
        ]);

        $stored = $this->normalizeCostEngineSettings(
            is_array($settings['cost_engine'] ?? null) ? $settings['cost_engine'] : []
        );

        $channels = ['email', 'sms', 'whatsapp'];
        $providerCosts = [];
        $overhead = [];
        $revenue = [];

        foreach ($channels as $channel) {
            $providerCosts[$channel] = array_merge(
                is_array($defaults['provider_costs'][$channel] ?? null) ? $defaults['provider_costs'][$channel] : [],
                is_array($stored['provider_costs'][$channel] ?? null) ? $stored['provider_costs'][$channel] : [],
            );

            $overhead[$channel] = (float) (
                $stored['overhead_per_message'][$channel]
                ?? $defaults['overhead_per_message'][$channel]
                ?? 0.0
            );

            $revenue[$channel] = (float) (
                $stored['revenue_per_message'][$channel]
                ?? $defaults['revenue_per_message'][$channel]
                ?? 0.0
            );
        }

        return [
            'provider_costs' => $providerCosts,
            'overhead_per_message' => $overhead,
            'revenue_per_message' => $revenue,
            'margin_alert_threshold_percent' => (float) (
                $stored['margin_alert_threshold_percent']
                ?? $defaults['margin_alert_threshold_percent']
                ?? 15.0
            ),
            'margin_alert_min_messages' => (int) (
                $stored['margin_alert_min_messages']
                ?? $defaults['margin_alert_min_messages']
                ?? 10
            ),
        ];
    }

    /**
     * Merge incoming tenant cost-engine overrides with existing settings.
     *
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $incoming
     * @return array<string, mixed>
     */
    private function mergeCostEngineSettings(array $settings, array $incoming): array
    {
        $merged = $this->normalizeCostEngineSettings(
            is_array($settings['cost_engine'] ?? null) ? $settings['cost_engine'] : []
        );

        $channels = ['email', 'sms', 'whatsapp'];
        $incomingProviderCosts = is_array($incoming['provider_costs'] ?? null) ? $incoming['provider_costs'] : [];

        foreach ($channels as $channel) {
            if (! array_key_exists($channel, $incomingProviderCosts) || ! is_array($incomingProviderCosts[$channel])) {
                continue;
            }

            $merged['provider_costs'][$channel] = is_array($merged['provider_costs'][$channel] ?? null)
                ? $merged['provider_costs'][$channel]
                : [];

            foreach ($incomingProviderCosts[$channel] as $provider => $rate) {
                $providerKey = mb_strtolower(trim((string) $provider));

                if ($providerKey === '') {
                    continue;
                }

                if ($rate === null || (is_string($rate) && trim($rate) === '')) {
                    unset($merged['provider_costs'][$channel][$providerKey]);
                    continue;
                }

                if (is_numeric($rate)) {
                    $merged['provider_costs'][$channel][$providerKey] = max(0, round((float) $rate, 4));
                }
            }

            if ($merged['provider_costs'][$channel] === []) {
                unset($merged['provider_costs'][$channel]);
            } else {
                ksort($merged['provider_costs'][$channel]);
            }
        }

        foreach (['overhead_per_message', 'revenue_per_message'] as $metric) {
            $incomingMetric = is_array($incoming[$metric] ?? null) ? $incoming[$metric] : [];

            foreach ($channels as $channel) {
                if (! array_key_exists($channel, $incomingMetric)) {
                    continue;
                }

                $value = $incomingMetric[$channel];

                if ($value === null || (is_string($value) && trim($value) === '')) {
                    unset($merged[$metric][$channel]);
                    continue;
                }

                if (is_numeric($value)) {
                    $merged[$metric][$channel] = max(0, round((float) $value, 4));
                }
            }
        }

        if (array_key_exists('margin_alert_threshold_percent', $incoming)) {
            $threshold = $incoming['margin_alert_threshold_percent'];

            if ($threshold === null || (is_string($threshold) && trim($threshold) === '')) {
                unset($merged['margin_alert_threshold_percent']);
            } elseif (is_numeric($threshold)) {
                $merged['margin_alert_threshold_percent'] = max(-100, min(100, round((float) $threshold, 4)));
            }
        }

        if (array_key_exists('margin_alert_min_messages', $incoming)) {
            $minimum = $incoming['margin_alert_min_messages'];

            if ($minimum === null || (is_string($minimum) && trim($minimum) === '')) {
                unset($merged['margin_alert_min_messages']);
            } elseif (is_numeric($minimum)) {
                $merged['margin_alert_min_messages'] = max(1, (int) $minimum);
            }
        }

        return $merged;
    }

    /**
     * Normalize structure and numeric ranges for cost-engine settings.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalizeCostEngineSettings(array $payload): array
    {
        $channels = ['email', 'sms', 'whatsapp'];
        $normalized = [
            'provider_costs' => [],
            'overhead_per_message' => [],
            'revenue_per_message' => [],
        ];

        $providerCosts = is_array($payload['provider_costs'] ?? null) ? $payload['provider_costs'] : [];

        foreach ($channels as $channel) {
            $channelProviders = is_array($providerCosts[$channel] ?? null) ? $providerCosts[$channel] : [];
            $mapped = [];

            foreach ($channelProviders as $provider => $rate) {
                $providerKey = mb_strtolower(trim((string) $provider));

                if ($providerKey === '' || ! is_numeric($rate)) {
                    continue;
                }

                $mapped[$providerKey] = max(0, round((float) $rate, 4));
            }

            if ($mapped !== []) {
                ksort($mapped);
                $normalized['provider_costs'][$channel] = $mapped;
            }
        }

        foreach (['overhead_per_message', 'revenue_per_message'] as $metric) {
            $values = is_array($payload[$metric] ?? null) ? $payload[$metric] : [];

            foreach ($channels as $channel) {
                if (! is_numeric($values[$channel] ?? null)) {
                    continue;
                }

                $normalized[$metric][$channel] = max(0, round((float) $values[$channel], 4));
            }
        }

        if (is_numeric($payload['margin_alert_threshold_percent'] ?? null)) {
            $normalized['margin_alert_threshold_percent'] = max(
                -100,
                min(100, round((float) $payload['margin_alert_threshold_percent'], 4))
            );
        }

        if (is_numeric($payload['margin_alert_min_messages'] ?? null)) {
            $normalized['margin_alert_min_messages'] = max(1, (int) $payload['margin_alert_min_messages']);
        }

        return $normalized;
    }

    /**
     * @return list<string>
     */
    private function allowedResidencyRegions(): array
    {
        $regions = config('tenant_encryption.residency_regions', []);

        if (! is_array($regions)) {
            return ['global'];
        }

        $normalized = array_values(array_filter(array_map(
            static fn (mixed $region): string => is_string($region) ? trim(mb_strtolower($region)) : '',
            $regions
        )));

        return $normalized !== [] ? array_values(array_unique($normalized)) : ['global'];
    }

    private function normalizeDataResidencyRegion(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $region = trim(mb_strtolower($value));

        return $region !== '' ? $region : null;
    }

    /**
     * Resolve tenant for read/write operations.
     */
    private function resolveTenantForRequest(Request $request, bool $allowBypass): Tenant
    {
        $tenantId = $request->attributes->get('tenant_id');

        if (is_int($tenantId) && $tenantId > 0) {
            $tenant = Tenant::query()->whereKey($tenantId)->first();

            if ($tenant !== null) {
                return $tenant;
            }
        }

        $requestedTenantId = $request->query('tenant_id', $request->input('tenant_id'));

        if (is_numeric($requestedTenantId) && (int) $requestedTenantId > 0) {
            $tenant = Tenant::query()->whereKey((int) $requestedTenantId)->first();

            if ($tenant !== null) {
                return $tenant;
            }
        }

        if ($allowBypass && (bool) $request->attributes->get('tenant_bypassed', false)) {
            abort(422, 'Select tenant_id to load settings while in bypass mode.');
        }

        abort(422, 'Tenant context is required for settings.');
    }

}
