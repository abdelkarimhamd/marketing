<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\BotAutomationService;
use App\Services\BotConversationService;
use App\Services\BrandResolutionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicChatController extends Controller
{
    /**
     * Return website chat widget configuration for one tenant.
     */
    public function widget(
        Request $request,
        BotAutomationService $botAutomationService,
        BrandResolutionService $brandResolutionService
    ): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        if (! $tenant instanceof Tenant) {
            abort(422, 'Tenant context is missing.');
        }

        $settings = $botAutomationService->settingsForTenant($tenant);
        $brand = $brandResolutionService->resolveForTenant($tenant, $request);
        $branding = $brandResolutionService->mergedBranding($tenant, $brand);

        return response()->json([
            'tenant' => [
                'id' => (int) $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'domain' => $tenant->domain,
                'timezone' => $tenant->timezone,
                'locale' => $tenant->locale,
            ],
            'brand' => $brand?->only([
                'id',
                'name',
                'slug',
                'landing_domain',
                'email_from_address',
                'sms_sender_id',
                'whatsapp_phone_number_id',
            ]),
            'branding' => $branding,
            'chat' => [
                'enabled' => (bool) ($settings['enabled'] ?? true)
                    && (bool) data_get($settings, 'channels.website_chat', true),
                'welcome_message' => (string) ($settings['welcome_message'] ?? ''),
                'default_reply' => (string) ($settings['default_reply'] ?? ''),
                'handoff_reply' => (string) ($settings['handoff_reply'] ?? ''),
                'qualification' => [
                    'enabled' => (bool) data_get($settings, 'qualification.enabled', true),
                    'questions' => array_values(array_map(
                        static fn (array $question): array => [
                            'key' => $question['key'] ?? null,
                            'question' => $question['question'] ?? null,
                            'required' => (bool) ($question['required'] ?? true),
                        ],
                        is_array(data_get($settings, 'qualification.questions', []))
                            ? data_get($settings, 'qualification.questions', [])
                            : []
                    )),
                ],
                'faq_count' => count(is_array($settings['faq'] ?? null) ? $settings['faq'] : []),
                'appointment' => [
                    'enabled' => (bool) data_get($settings, 'appointment.enabled', true),
                    'booking_url' => data_get($settings, 'appointment.booking_url'),
                ],
            ],
        ]);
    }

    /**
     * Capture website chat message, run bot flow and return reply.
     */
    public function message(
        Request $request,
        BotConversationService $botConversationService,
        BrandResolutionService $brandResolutionService
    ): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        if (! $tenant instanceof Tenant) {
            abort(422, 'Tenant context is missing.');
        }

        $payload = $request->validate([
            'session_id' => ['nullable', 'string', 'max:120'],
            'message' => ['nullable', 'string', 'max:5000'],
            'request_handoff' => ['nullable', 'boolean'],
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'company' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:150'],
            'country_code' => ['nullable', 'string', 'max:8'],
            'interest' => ['nullable', 'string', 'max:150'],
            'service' => ['nullable', 'string', 'max:150'],
            'locale' => ['nullable', 'string', 'max:12'],
            'brand_id' => ['nullable', 'integer', 'min:1'],
            'brand_slug' => ['nullable', 'string', 'max:120'],
            'meta' => ['nullable', 'array'],
        ]);

        $brand = $brandResolutionService->resolveForTenant($tenant, $request, $payload);
        $explicitBrandRequested = is_numeric($payload['brand_id'] ?? null)
            || (is_string($payload['brand_slug'] ?? null) && trim((string) $payload['brand_slug']) !== '');

        if ($explicitBrandRequested && $brand === null) {
            abort(422, 'Provided brand_id/brand_slug was not found for tenant.');
        }

        $message = trim((string) ($payload['message'] ?? ''));
        $requestHandoff = (bool) ($payload['request_handoff'] ?? false);

        if ($message === '' && ! $requestHandoff) {
            abort(422, 'message is required unless request_handoff is true.');
        }

        if ($message === '' && $requestHandoff) {
            $payload['message'] = 'agent';
        }

        $result = $botConversationService->processWebsiteChat(
            tenant: $tenant,
            payload: $payload,
            brand: $brand,
            requestMeta: [
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'origin' => $request->header('Origin'),
                'referer' => $request->header('Referer'),
                'tenant_resolution_source' => $request->attributes->get('tenant_resolution_source'),
            ],
        );

        return response()->json([
            'message' => 'Chat message processed.',
            'session_id' => $result['session_id'],
            'thread_key' => $result['thread_key'],
            'lead' => $result['lead'],
            'brand' => $result['brand'] ?? null,
            'bot_reply' => $result['bot_reply'],
            'handoff_requested' => (bool) ($result['handoff_requested'] ?? false),
            'qualified' => (bool) ($result['qualified'] ?? false),
            'bot' => $result['bot'] ?? [],
        ]);
    }
}
