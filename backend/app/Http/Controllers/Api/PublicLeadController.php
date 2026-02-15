<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\LeadForm;
use App\Models\Lead;
use App\Models\Tag;
use App\Models\Tenant;
use App\Services\BrandResolutionService;
use App\Services\ConsentService;
use App\Services\CustomFieldService;
use App\Services\LeadAssignmentService;
use App\Services\LeadEnrichmentService;
use App\Services\RealtimeEventService;
use App\Support\UnsubscribeToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PublicLeadController extends Controller
{
    /**
     * Store a public lead from website form or API source.
     */
    public function store(
        Request $request,
        UnsubscribeToken $unsubscribeToken,
        LeadAssignmentService $assignmentService,
        LeadEnrichmentService $leadEnrichmentService,
        BrandResolutionService $brandResolutionService,
        CustomFieldService $customFieldService,
        ConsentService $consentService,
        RealtimeEventService $eventService
    ): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        if (! $tenant instanceof Tenant) {
            abort(422, 'Tenant context is missing.');
        }

        $data = $request->validate([
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'company' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:150'],
            'country_code' => ['nullable', 'string', 'max:8'],
            'interest' => ['nullable', 'string', 'max:150'],
            'service' => ['nullable', 'string', 'max:150'],
            'title' => ['nullable', 'string', 'max:255'],
            'source' => ['nullable', 'string', 'max:100'],
            'message' => ['nullable', 'string', 'max:5000'],
            'tags' => ['nullable', 'array', 'max:25'],
            'tags.*' => ['string', 'max:80'],
            'meta' => ['nullable', 'array'],
            'locale' => ['nullable', 'string', 'max:12'],
            'email_consent' => ['nullable', 'boolean'],
            'auto_assign' => ['nullable', 'boolean'],
            'form_slug' => ['nullable', 'string', 'max:120'],
            'brand_id' => ['nullable', 'integer', 'min:1'],
            'brand_slug' => ['nullable', 'string', 'max:120'],
            'website' => ['prohibited'],
            'api_key' => ['nullable', 'string', 'max:255'],
        ]);

        $formSlug = trim((string) ($data['form_slug'] ?? $request->header('X-Form-Slug', '')));
        $mappedLeadData = [];
        $mappedCustomValues = [];

        if ($formSlug !== '') {
            $form = LeadForm::query()
                ->withoutTenancy()
                ->where('tenant_id', $tenant->id)
                ->where('slug', $formSlug)
                ->where('is_active', true)
                ->first();

            if ($form === null) {
                abort(422, 'Provided form_slug was not found for tenant.');
            }

            $mapped = $customFieldService->mapFormPayload($form, $request->all());
            $mappedLeadData = is_array($mapped['lead'] ?? null) ? $mapped['lead'] : [];
            $mappedCustomValues = is_array($mapped['custom_values'] ?? null) ? $mapped['custom_values'] : [];
        }

        $data = array_merge($mappedLeadData, $data);
        $data = $leadEnrichmentService->enrich($data);
        $brand = $brandResolutionService->resolveForTenant($tenant, $request, $data);
        $explicitBrandRequested = is_numeric($data['brand_id'] ?? null)
            || (is_string($data['brand_slug'] ?? null) && trim((string) $data['brand_slug']) !== '');

        if ($explicitBrandRequested && $brand === null) {
            abort(422, 'Provided brand_id/brand_slug was not found for tenant.');
        }

        if (empty($data['email']) && empty($data['phone'])) {
            abort(422, 'email or phone is required.');
        }

        /** @var Lead $lead */
        $lead = DB::transaction(function () use (
            $tenant,
            $request,
            $data,
            $brand,
            $customFieldService,
            $mappedCustomValues
        ): Lead {
            $meta = is_array($data['meta'] ?? null) ? $data['meta'] : [];
            $meta['intake'] = [
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'origin' => $request->header('Origin'),
                'referer' => $request->header('Referer'),
                'tenant_resolution_source' => $request->attributes->get('tenant_resolution_source'),
                'received_at' => now()->toIso8601String(),
                'brand_id' => $brand?->id,
                'brand_slug' => $brand?->slug,
            ];

            $lead = Lead::query()->create([
                'tenant_id' => $tenant->id,
                'brand_id' => $brand?->id,
                'first_name' => $data['first_name'] ?? null,
                'last_name' => $data['last_name'] ?? null,
                'email' => $data['email'] ?? null,
                'email_consent' => array_key_exists('email_consent', $data)
                    ? (bool) $data['email_consent']
                    : true,
                'consent_updated_at' => now(),
                'phone' => $data['phone'] ?? null,
                'company' => $data['company'] ?? null,
                'city' => $data['city'] ?? null,
                'country_code' => $data['country_code'] ?? null,
                'interest' => $data['interest'] ?? null,
                'service' => $data['service'] ?? null,
                'title' => $data['title'] ?? null,
                'status' => 'new',
                'source' => $data['source']
                    ?? ($request->header('X-Api-Key') ? 'api' : 'website'),
                'locale' => $data['locale'] ?? null,
                'meta' => $meta,
            ]);

            $tags = collect($data['tags'] ?? [])
                ->map(static fn (string $tag): string => trim($tag))
                ->filter()
                ->unique()
                ->values();

            if ($tags->isNotEmpty()) {
                $tagIds = [];

                foreach ($tags as $tagName) {
                    $tag = Tag::query()->firstOrCreate(
                        [
                            'tenant_id' => $tenant->id,
                            'slug' => Str::slug($tagName),
                        ],
                        [
                            'name' => $tagName,
                        ]
                    );

                    $tagIds[$tag->id] = ['tenant_id' => $tenant->id];
                }

                $lead->tags()->syncWithoutDetaching($tagIds);
            }

            Activity::query()->create([
                'tenant_id' => $tenant->id,
                'actor_id' => null,
                'type' => 'lead.intake.created',
                'subject_type' => Lead::class,
                'subject_id' => $lead->id,
                'description' => 'Lead created from public intake endpoint.',
                'properties' => [
                    'source' => $lead->source,
                    'message' => $data['message'] ?? null,
                    'tenant_resolution_source' => $request->attributes->get('tenant_resolution_source'),
                    'brand_id' => $brand?->id,
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ],
            ]);

            $customFieldService->upsertLeadValues($lead, $mappedCustomValues);

            return $lead;
        });

        if (is_string($lead->email) && $lead->email !== '') {
            $consentService->recordLeadConsent(
                lead: $lead,
                channel: 'email',
                granted: (bool) ($data['email_consent'] ?? true),
                source: 'public_intake',
                proofMethod: 'public_form',
                proofRef: $formSlug !== '' ? $formSlug : 'default',
                context: [
                    'tenant_resolution_source' => $request->attributes->get('tenant_resolution_source'),
                    'origin' => $request->header('Origin'),
                ],
                ipAddress: $request->ip(),
                userAgent: $request->userAgent(),
            );
        }

        if ($data['auto_assign'] ?? true) {
            $assignmentService->assignLead($lead->refresh(), 'intake');
        }

        $preference = $consentService->ensureLeadPreference($lead->refresh());
        $preferenceUrl = route('public.preferences.show', ['token' => $preference->token]);

        $eventService->emit(
            eventName: 'lead.created',
            tenantId: (int) $lead->tenant_id,
            subjectType: Lead::class,
            subjectId: (int) $lead->id,
            payload: [
                'source' => $lead->source,
                'tenant_resolution_source' => $request->attributes->get('tenant_resolution_source'),
                'brand_id' => $lead->brand_id,
            ],
        );

        $unsubscribeUrl = null;

        if (is_string($lead->email) && $lead->email !== '') {
            $token = $unsubscribeToken->make(
                tenantId: (int) $lead->tenant_id,
                channel: 'email',
                value: $lead->email,
                leadId: (int) $lead->id,
            );

            $unsubscribeUrl = route('public.unsubscribe', ['token' => $token]);
        }

        return response()->json([
            'message' => 'Lead created successfully.',
            'lead' => $lead->load('tags'),
            'brand' => $brand?->only(['id', 'name', 'slug', 'landing_domain']),
            'unsubscribe_url' => $unsubscribeUrl,
            'preferences_url' => $preferenceUrl,
        ], 201);
    }
}
