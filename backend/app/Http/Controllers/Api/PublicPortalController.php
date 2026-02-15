<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Appointment;
use App\Models\Attachment;
use App\Models\Lead;
use App\Models\LeadForm;
use App\Models\PortalRequest;
use App\Models\Tag;
use App\Models\Team;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AppointmentBookingService;
use App\Services\AttachmentService;
use App\Services\BrandResolutionService;
use App\Services\ConsentService;
use App\Services\CustomFieldService;
use App\Services\LeadAssignmentService;
use App\Services\LeadEnrichmentService;
use App\Services\RealtimeEventService;
use App\Support\PortalTrackingToken;
use App\Support\UnsubscribeToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PublicPortalController extends Controller
{
    /**
     * Return tenant-branded public customer portal configuration.
     */
    public function show(Request $request, BrandResolutionService $brandResolutionService): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        if (! $tenant instanceof Tenant) {
            abort(422, 'Tenant context is missing.');
        }

        $portalSettings = $this->portalSettingsForTenant($tenant);
        $portalSettings['booking']['links'] = $this->bookingLinksForTenant($tenant);
        $brand = $brandResolutionService->resolveForTenant($tenant, $request);
        $branding = $brandResolutionService->mergedBranding($tenant, $brand);

        $forms = LeadForm::query()
            ->withoutTenancy()
            ->with(['fields' => fn ($query) => $query->orderBy('sort_order')])
            ->where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(function (LeadForm $form): array {
                return [
                    'id' => $form->id,
                    'name' => $form->name,
                    'slug' => $form->slug,
                    'fields' => $form->fields->map(static function ($field): array {
                        return [
                            'id' => $field->id,
                            'label' => $field->label,
                            'source_key' => $field->source_key,
                            'map_to' => $field->map_to,
                            'is_required' => (bool) $field->is_required,
                            'sort_order' => (int) $field->sort_order,
                            'validation_rules' => is_array($field->validation_rules) ? $field->validation_rules : [],
                        ];
                    })->all(),
                ];
            })
            ->values()
            ->all();

        return response()->json([
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'domain' => $tenant->domain,
                'timezone' => $tenant->timezone,
                'locale' => $tenant->locale,
                'currency' => $tenant->currency,
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
            'landing_page' => is_array($brand?->landing_page) ? $brand->landing_page : [],
            'portal' => [
                ...$portalSettings,
                'upload_limits' => [
                    'max_files_per_request' => (int) config('attachments.max_files_per_request', 10),
                    'max_file_size_kb' => (int) config('attachments.max_file_size_kb', 10240),
                    'allowed_mime_types' => is_array(config('attachments.allowed_mime_types', []))
                        ? array_values(config('attachments.allowed_mime_types', []))
                        : [],
                ],
                'forms' => $forms,
            ],
        ]);
    }

    /**
     * Submit a quote request from tenant-branded portal.
     */
    public function requestQuote(
        Request $request,
        UnsubscribeToken $unsubscribeToken,
        PortalTrackingToken $portalTrackingToken,
        LeadAssignmentService $assignmentService,
        LeadEnrichmentService $leadEnrichmentService,
        BrandResolutionService $brandResolutionService,
        CustomFieldService $customFieldService,
        ConsentService $consentService,
        RealtimeEventService $eventService
    ): JsonResponse {
        return $this->createPortalLead(
            request: $request,
            intent: 'request_quote',
            eventName: 'portal.quote.requested',
            activityType: 'portal.quote.requested',
            activityDescription: 'Quote request submitted from customer portal.',
            extraValidation: [
                'quote_budget' => ['nullable', 'string', 'max:120'],
                'quote_timeline' => ['nullable', 'string', 'max:120'],
                'message' => ['nullable', 'string', 'max:5000'],
            ],
            portalMetaResolver: static fn (array $data, Tenant $tenant): array => [
                'quote' => [
                    'budget' => $data['quote_budget'] ?? null,
                    'timeline' => $data['quote_timeline'] ?? null,
                    'message' => $data['message'] ?? null,
                ],
            ],
            successMessage: 'Quote request submitted successfully.',
            unsubscribeToken: $unsubscribeToken,
            portalTrackingToken: $portalTrackingToken,
            assignmentService: $assignmentService,
            leadEnrichmentService: $leadEnrichmentService,
            brandResolutionService: $brandResolutionService,
            customFieldService: $customFieldService,
            consentService: $consentService,
            eventService: $eventService,
        );
    }

    /**
     * Submit a demo booking from tenant-branded portal.
     */
    public function bookDemo(
        Request $request,
        UnsubscribeToken $unsubscribeToken,
        PortalTrackingToken $portalTrackingToken,
        LeadAssignmentService $assignmentService,
        LeadEnrichmentService $leadEnrichmentService,
        BrandResolutionService $brandResolutionService,
        CustomFieldService $customFieldService,
        ConsentService $consentService,
        RealtimeEventService $eventService,
        AppointmentBookingService $appointmentBookingService,
    ): JsonResponse {
        return $this->createPortalLead(
            request: $request,
            intent: 'book_demo',
            eventName: 'portal.demo.booked',
            activityType: 'portal.demo.booked',
            activityDescription: 'Demo booking submitted from customer portal.',
            extraValidation: [
                'preferred_at' => ['required', 'date'],
                'booking_timezone' => ['nullable', 'string', 'max:64'],
                'booking_channel' => ['nullable', 'string', 'max:50'],
                'message' => ['nullable', 'string', 'max:5000'],
            ],
            portalMetaResolver: function (array $data, Tenant $tenant): array {
                $bookingTimezone = is_string($data['booking_timezone'] ?? null)
                    ? trim((string) $data['booking_timezone'])
                    : '';
                $portalSettings = $this->portalSettingsForTenant($tenant);
                $defaultTimezone = trim((string) data_get($portalSettings, 'booking.default_timezone', $tenant->timezone ?? 'UTC'));

                return [
                    'booking' => [
                        'preferred_at' => $data['preferred_at'] ?? null,
                        'timezone' => $bookingTimezone !== '' ? $bookingTimezone : $defaultTimezone,
                        'channel' => $data['booking_channel'] ?? null,
                        'message' => $data['message'] ?? null,
                    ],
                ];
            },
            nextFollowUpResolver: static function (array $data): ?Carbon {
                if (! is_string($data['preferred_at'] ?? null) || trim((string) $data['preferred_at']) === '') {
                    return null;
                }

                try {
                    return Carbon::parse((string) $data['preferred_at']);
                } catch (\Throwable) {
                    return null;
                }
            },
            successMessage: 'Demo booking submitted successfully.',
            unsubscribeToken: $unsubscribeToken,
            portalTrackingToken: $portalTrackingToken,
            assignmentService: $assignmentService,
            leadEnrichmentService: $leadEnrichmentService,
            brandResolutionService: $brandResolutionService,
            customFieldService: $customFieldService,
            consentService: $consentService,
            eventService: $eventService,
            appointmentBookingService: $appointmentBookingService,
        );
    }

    /**
     * Upload customer documents to one lead via secure tracking token.
     */
    public function uploadDocuments(
        Request $request,
        PortalTrackingToken $portalTrackingToken,
        AttachmentService $attachmentService,
        RealtimeEventService $eventService
    ): JsonResponse {
        $tenant = $request->attributes->get('tenant');

        if (! $tenant instanceof Tenant) {
            abort(422, 'Tenant context is missing.');
        }

        $portalSettings = $this->portalSettingsForTenant($tenant);

        if (! (bool) data_get($portalSettings, 'features.upload_docs', true)) {
            abort(403, 'Document upload is disabled for this portal.');
        }

        $maxFiles = max(1, (int) config('attachments.max_files_per_request', 10));
        $maxKb = max(1, (int) config('attachments.max_file_size_kb', 10240));

        $payload = $request->validate([
            'tracking_token' => ['required', 'string', 'max:4096'],
            'kind' => ['nullable', 'string', 'max:64'],
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'meta' => ['nullable', 'array'],
            'files' => ['required', 'array', 'min:1', 'max:'.$maxFiles],
            'files.*' => ['required', 'file', 'max:'.$maxKb],
        ]);

        $tokenPayload = $portalTrackingToken->parse((string) $payload['tracking_token']);

        if (! is_array($tokenPayload)) {
            abort(404, 'Tracking token is invalid or expired.');
        }

        if ((int) $tokenPayload['tenant_id'] !== (int) $tenant->id) {
            abort(409, 'Tracking token tenant mismatch.');
        }

        $lead = Lead::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenant->id)
            ->whereKey((int) $tokenPayload['lead_id'])
            ->first();

        if (! $lead instanceof Lead) {
            abort(404, 'Lead not found for tracking token.');
        }

        $portalRequest = PortalRequest::query()->withoutTenancy()->create([
            'tenant_id' => (int) $tenant->id,
            'lead_id' => (int) $lead->id,
            'request_type' => 'upload_documents',
            'status' => 'in_progress',
            'payload_json' => [
                'kind' => $payload['kind'] ?? 'document',
                'title' => $payload['title'] ?? null,
                'description' => $payload['description'] ?? null,
            ],
            'meta' => [
                'tracking_intent' => (string) $tokenPayload['intent'],
            ],
            'source_ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        $created = [];

        foreach ($request->file('files', []) as $file) {
            if (! $file instanceof \Illuminate\Http\UploadedFile) {
                continue;
            }

            $attachment = $attachmentService->storeUploadedFile($tenant, $file, [
                'entity_type' => 'lead',
                'entity_id' => (int) $lead->id,
                'kind' => $payload['kind'] ?? 'document',
                'source' => 'portal',
                'title' => $payload['title'] ?? null,
                'description' => $payload['description'] ?? null,
                'meta' => is_array($payload['meta'] ?? null) ? $payload['meta'] : [],
                'uploaded_by' => null,
            ]);

            Activity::query()->withoutTenancy()->create([
                'tenant_id' => (int) $tenant->id,
                'actor_id' => null,
                'type' => 'attachment.uploaded',
                'subject_type' => Attachment::class,
                'subject_id' => (int) $attachment->id,
                'description' => 'Attachment uploaded from customer portal.',
                'properties' => [
                    'entity_type' => 'lead',
                    'entity_id' => (int) $lead->id,
                    'original_name' => $attachment->original_name,
                    'mime_type' => $attachment->mime_type,
                    'size_bytes' => $attachment->size_bytes,
                    'scan_status' => $attachment->scan_status,
                    'source' => 'portal',
                    'brand_id' => $lead->brand_id,
                ],
            ]);

            $created[] = $attachment;
        }

        $meta = is_array($lead->meta) ? $lead->meta : [];
        $existingUploads = (int) data_get($meta, 'portal.documents.total_uploaded', 0);

        data_set($meta, 'portal.documents.total_uploaded', $existingUploads + count($created));
        data_set($meta, 'portal.documents.last_uploaded_at', now()->toIso8601String());
        data_set($meta, 'portal.documents.last_upload_count', count($created));

        $lead->forceFill(['meta' => $meta])->save();

        Activity::query()->withoutTenancy()->create([
            'tenant_id' => (int) $tenant->id,
            'actor_id' => null,
            'type' => 'portal.documents.uploaded',
            'subject_type' => Lead::class,
            'subject_id' => (int) $lead->id,
            'description' => 'Customer uploaded documents from portal.',
            'properties' => [
                'attachments_count' => count($created),
                'tracking_intent' => (string) $tokenPayload['intent'],
                'brand_id' => $lead->brand_id,
            ],
        ]);

        $eventService->emit(
            eventName: 'portal.documents.uploaded',
            tenantId: (int) $tenant->id,
            subjectType: Lead::class,
            subjectId: (int) $lead->id,
            payload: [
                'attachments_count' => count($created),
                'tracking_intent' => (string) $tokenPayload['intent'],
                'brand_id' => $lead->brand_id,
            ],
        );

        $portalRequest->forceFill([
            'status' => 'qualified',
            'meta' => array_replace_recursive(
                is_array($portalRequest->meta) ? $portalRequest->meta : [],
                ['attachments_count' => count($created)]
            ),
        ])->save();

        return response()->json([
            'message' => 'Documents uploaded successfully.',
            'lead_id' => (int) $lead->id,
            'portal_request_id' => (int) $portalRequest->id,
            'attachments' => collect($created)->map(static function (Attachment $attachment): array {
                return [
                    'id' => (int) $attachment->id,
                    'title' => $attachment->title,
                    'original_name' => $attachment->original_name,
                    'mime_type' => $attachment->mime_type,
                    'size_bytes' => (int) $attachment->size_bytes,
                    'scan_status' => $attachment->scan_status,
                    'created_at' => optional($attachment->created_at)->toIso8601String(),
                ];
            })->values()->all(),
        ], 201);
    }

    /**
     * Track lead/deal progress using secure portal tracking token.
     */
    public function status(
        string $token,
        Request $request,
        PortalTrackingToken $portalTrackingToken,
        BrandResolutionService $brandResolutionService,
        RealtimeEventService $eventService
    ): JsonResponse {
        $tokenPayload = $portalTrackingToken->parse($token);

        if (! is_array($tokenPayload)) {
            abort(404, 'Tracking token is invalid or expired.');
        }

        $tenant = Tenant::query()
            ->whereKey((int) $tokenPayload['tenant_id'])
            ->where('is_active', true)
            ->first();

        if (! $tenant instanceof Tenant) {
            abort(404, 'Tenant not found for tracking token.');
        }

        $resolvedTenant = $request->attributes->get('tenant');

        if ($resolvedTenant instanceof Tenant && (int) $resolvedTenant->id !== (int) $tenant->id) {
            abort(409, 'Tracking token tenant mismatch.');
        }

        $portalSettings = $this->portalSettingsForTenant($tenant);

        if (! (bool) data_get($portalSettings, 'features.track_status', true)) {
            abort(403, 'Status tracking is disabled for this portal.');
        }

        $lead = Lead::query()
            ->withoutTenancy()
            ->with(['owner:id,name,email', 'team:id,name', 'tags:id,name,slug,color', 'brand:id,tenant_id,name,slug,landing_domain,branding,landing_page,email_from_address,sms_sender_id,whatsapp_phone_number_id'])
            ->where('tenant_id', $tenant->id)
            ->whereKey((int) $tokenPayload['lead_id'])
            ->first();

        if (! $lead instanceof Lead) {
            abort(404, 'Lead not found for tracking token.');
        }

        $brand = $lead->brand;
        $branding = $brandResolutionService->mergedBranding($tenant, $brand);

        $attachments = Attachment::query()
            ->withoutTenancy()
            ->where('tenant_id', (int) $tenant->id)
            ->where('entity_type', 'lead')
            ->where('entity_id', (int) $lead->id)
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        $activities = Activity::query()
            ->withoutTenancy()
            ->where('tenant_id', (int) $tenant->id)
            ->where('subject_type', Lead::class)
            ->where('subject_id', (int) $lead->id)
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        Activity::query()->withoutTenancy()->create([
            'tenant_id' => (int) $tenant->id,
            'actor_id' => null,
            'type' => 'portal.status.viewed',
            'subject_type' => Lead::class,
            'subject_id' => (int) $lead->id,
            'description' => 'Customer viewed portal tracking status.',
            'properties' => [
                'intent' => (string) $tokenPayload['intent'],
                'brand_id' => $lead->brand_id,
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ],
        ]);

        $eventService->emit(
            eventName: 'portal.status.viewed',
            tenantId: (int) $tenant->id,
            subjectType: Lead::class,
            subjectId: (int) $lead->id,
            payload: [
                'intent' => (string) $tokenPayload['intent'],
                'brand_id' => $lead->brand_id,
            ],
        );

        return response()->json([
            'tenant' => [
                'id' => (int) $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'timezone' => $tenant->timezone,
                'locale' => $tenant->locale,
                'currency' => $tenant->currency,
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
            'landing_page' => is_array($brand?->landing_page) ? $brand->landing_page : [],
            'portal' => $portalSettings,
            'tracking' => [
                'intent' => (string) $tokenPayload['intent'],
                'expires_at' => Carbon::createFromTimestamp((int) $tokenPayload['exp'])->toIso8601String(),
            ],
            'lead' => [
                'id' => (int) $lead->id,
                'brand_id' => $lead->brand_id,
                'first_name' => $lead->first_name,
                'last_name' => $lead->last_name,
                'email' => $lead->email,
                'phone' => $lead->phone,
                'company' => $lead->company,
                'status' => $lead->status,
                'source' => $lead->source,
                'score' => $lead->score,
                'created_at' => optional($lead->created_at)->toIso8601String(),
                'updated_at' => optional($lead->updated_at)->toIso8601String(),
                'next_follow_up_at' => optional($lead->next_follow_up_at)->toIso8601String(),
                'owner' => $lead->owner?->only(['id', 'name', 'email']),
                'team' => $lead->team?->only(['id', 'name']),
                'tags' => $lead->tags->map(static fn (Tag $tag): array => [
                    'id' => $tag->id,
                    'name' => $tag->name,
                    'slug' => $tag->slug,
                    'color' => $tag->color,
                ])->values()->all(),
                'portal_meta' => is_array(data_get($lead->meta, 'portal')) ? data_get($lead->meta, 'portal') : [],
            ],
            'attachments' => $attachments->map(static function (Attachment $attachment): array {
                return [
                    'id' => (int) $attachment->id,
                    'title' => $attachment->title,
                    'description' => $attachment->description,
                    'kind' => $attachment->kind,
                    'source' => $attachment->source,
                    'original_name' => $attachment->original_name,
                    'mime_type' => $attachment->mime_type,
                    'size_bytes' => (int) $attachment->size_bytes,
                    'scan_status' => $attachment->scan_status,
                    'created_at' => optional($attachment->created_at)->toIso8601String(),
                ];
            })->values()->all(),
            'timeline' => $activities->map(static function (Activity $activity): array {
                return [
                    'id' => (int) $activity->id,
                    'type' => $activity->type,
                    'description' => $activity->description,
                    'properties' => is_array($activity->properties) ? $activity->properties : [],
                    'created_at' => optional($activity->created_at)->toIso8601String(),
                ];
            })->values()->all(),
        ]);
    }

    /**
     * Create portal lead with shared workflow for quote/demo intents.
     *
     * @param array<string, mixed> $extraValidation
     * @param \Closure(array<string, mixed>, Tenant):array<string, mixed> $portalMetaResolver
     * @param \Closure(array<string, mixed>):?Carbon|null $nextFollowUpResolver
     */
    private function createPortalLead(
        Request $request,
        string $intent,
        string $eventName,
        string $activityType,
        string $activityDescription,
        array $extraValidation,
        \Closure $portalMetaResolver,
        string $successMessage,
        UnsubscribeToken $unsubscribeToken,
        PortalTrackingToken $portalTrackingToken,
        LeadAssignmentService $assignmentService,
        LeadEnrichmentService $leadEnrichmentService,
        BrandResolutionService $brandResolutionService,
        CustomFieldService $customFieldService,
        ConsentService $consentService,
        RealtimeEventService $eventService,
        ?AppointmentBookingService $appointmentBookingService = null,
        ?\Closure $nextFollowUpResolver = null
    ): JsonResponse {
        $tenant = $request->attributes->get('tenant');

        if (! $tenant instanceof Tenant) {
            abort(422, 'Tenant context is missing.');
        }

        $portalSettings = $this->portalSettingsForTenant($tenant);

        if (! (bool) data_get($portalSettings, 'enabled', true)) {
            abort(403, 'Customer portal is disabled for this tenant.');
        }

        $featureKey = $intent === 'book_demo' ? 'book_demo' : 'request_quote';

        if (! (bool) data_get($portalSettings, "features.{$featureKey}", true)) {
            abort(403, 'Requested portal flow is disabled for this tenant.');
        }

        $baseValidation = [
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
            'tags' => ['nullable', 'array', 'max:25'],
            'tags.*' => ['string', 'max:80'],
            'meta' => ['nullable', 'array'],
            'locale' => ['nullable', 'string', 'max:12'],
            'email_consent' => ['nullable', 'boolean'],
            'auto_assign' => ['nullable', 'boolean'],
            'form_slug' => ['nullable', 'string', 'max:120'],
            'brand_id' => ['nullable', 'integer', 'min:1'],
            'brand_slug' => ['nullable', 'string', 'max:120'],
            'owner_id' => ['nullable', 'integer', 'min:1'],
            'team_id' => ['nullable', 'integer', 'min:1'],
            'website' => ['prohibited'],
            'api_key' => ['nullable', 'string', 'max:255'],
        ];

        $data = $request->validate(array_merge($baseValidation, $extraValidation));

        $formSlug = trim((string) ($data['form_slug'] ?? $request->header('X-Form-Slug', '')));
        $defaultFormSlug = trim((string) data_get($portalSettings, 'default_form_slug', ''));
        $effectiveFormSlug = $formSlug !== '' ? $formSlug : $defaultFormSlug;

        $mappedLeadData = [];
        $mappedCustomValues = [];

        if ($effectiveFormSlug !== '') {
            $form = LeadForm::query()
                ->withoutTenancy()
                ->where('tenant_id', $tenant->id)
                ->where('slug', $effectiveFormSlug)
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

        $requestedOwner = $this->resolvePortalOwner($tenant, $data['owner_id'] ?? null);
        $requestedTeam = $this->resolvePortalTeam($tenant, $data['team_id'] ?? null);

        $sourcePrefix = trim((string) data_get($portalSettings, 'source_prefix', 'portal'));
        $sourcePrefix = $sourcePrefix !== '' ? $sourcePrefix : 'portal';
        $defaultSource = $sourcePrefix.'_'.$intent;
        $source = is_string($data['source'] ?? null) && trim((string) $data['source']) !== ''
            ? trim((string) $data['source'])
            : $defaultSource;

        $defaultStatus = trim((string) data_get($portalSettings, 'default_status', 'new'));
        $defaultStatus = $defaultStatus !== '' ? $defaultStatus : 'new';

        $tags = $this->normalizeTags(array_merge(
            (array) data_get($portalSettings, 'default_tags', []),
            ['portal', str_replace('_', '-', $intent)],
            is_array($data['tags'] ?? null) ? $data['tags'] : []
        ));

        $portalMeta = $portalMetaResolver($data, $tenant);
        $nextFollowUpAt = $nextFollowUpResolver instanceof \Closure
            ? $nextFollowUpResolver($data)
            : null;

        $portalRequest = null;

        /** @var Lead $lead */
        $lead = DB::transaction(function () use (
            $tenant,
            $request,
            $data,
            $intent,
            $source,
            $defaultStatus,
            $tags,
            $brand,
            $portalMeta,
            $nextFollowUpAt,
            $requestedOwner,
            $requestedTeam,
            $customFieldService,
            $mappedCustomValues,
            $activityType,
            $activityDescription,
            &$portalRequest
        ): Lead {
            $meta = is_array($data['meta'] ?? null) ? $data['meta'] : [];
            $meta['intake'] = [
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'origin' => $request->header('Origin'),
                'referer' => $request->header('Referer'),
                'tenant_resolution_source' => $request->attributes->get('tenant_resolution_source'),
                'received_at' => now()->toIso8601String(),
                'channel' => 'customer_portal',
                'brand_id' => $brand?->id,
                'brand_slug' => $brand?->slug,
                'requested_owner_id' => $requestedOwner?->id,
                'requested_team_id' => $requestedTeam?->id,
            ];

            $existingPortalMeta = is_array($meta['portal'] ?? null) ? $meta['portal'] : [];
            $meta['portal'] = array_replace_recursive($existingPortalMeta, [
                'intent' => $intent,
                'status' => 'submitted',
                'submitted_at' => now()->toIso8601String(),
            ], is_array($portalMeta) ? $portalMeta : []);

            $lead = Lead::query()->create([
                'tenant_id' => $tenant->id,
                'brand_id' => $brand?->id,
                'team_id' => $requestedTeam?->id,
                'owner_id' => $requestedOwner?->id,
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
                'status' => $defaultStatus,
                'source' => $source,
                'locale' => $data['locale'] ?? null,
                'next_follow_up_at' => $nextFollowUpAt,
                'meta' => $meta,
            ]);

            if ($tags !== []) {
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
                'description' => 'Lead created from customer portal.',
                'properties' => [
                    'source' => $lead->source,
                    'intent' => $intent,
                    'tenant_resolution_source' => $request->attributes->get('tenant_resolution_source'),
                    'brand_id' => $brand?->id,
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ],
            ]);

            Activity::query()->create([
                'tenant_id' => $tenant->id,
                'actor_id' => null,
                'type' => $activityType,
                'subject_type' => Lead::class,
                'subject_id' => $lead->id,
                'description' => $activityDescription,
                'properties' => [
                    'source' => $lead->source,
                    'intent' => $intent,
                    'brand_id' => $brand?->id,
                    'portal' => is_array($portalMeta) ? $portalMeta : [],
                ],
            ]);

            $customFieldService->upsertLeadValues($lead, $mappedCustomValues);

            $portalRequest = PortalRequest::query()->withoutTenancy()->create([
                'tenant_id' => (int) $tenant->id,
                'lead_id' => (int) $lead->id,
                'request_type' => $intent,
                'status' => 'new',
                'payload_json' => [
                    'first_name' => $lead->first_name,
                    'last_name' => $lead->last_name,
                    'email' => $lead->email,
                    'phone' => $lead->phone,
                    'company' => $lead->company,
                    'city' => $lead->city,
                    'country_code' => $lead->country_code,
                    'source' => $lead->source,
                    'portal' => is_array($portalMeta) ? $portalMeta : [],
                ],
                'meta' => [
                    'brand_id' => $brand?->id,
                    'tenant_resolution_source' => $request->attributes->get('tenant_resolution_source'),
                ],
                'source_ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'assigned_to' => $requestedOwner?->id,
            ]);

            return $lead;
        });

        if (is_string($lead->email) && $lead->email !== '') {
            $consentService->recordLeadConsent(
                lead: $lead,
                channel: 'email',
                granted: (bool) ($data['email_consent'] ?? true),
                source: 'customer_portal',
                proofMethod: 'portal_form',
                proofRef: $effectiveFormSlug !== '' ? $effectiveFormSlug : $intent,
                context: [
                    'tenant_resolution_source' => $request->attributes->get('tenant_resolution_source'),
                    'origin' => $request->header('Origin'),
                    'intent' => $intent,
                ],
                ipAddress: $request->ip(),
                userAgent: $request->userAgent(),
            );
        }

        $autoAssign = array_key_exists('auto_assign', $data)
            ? (bool) $data['auto_assign']
            : (bool) data_get($portalSettings, 'auto_assign', true);
        $hasExplicitAssignment = $lead->owner_id !== null || $lead->team_id !== null;

        if ($autoAssign && ! $hasExplicitAssignment) {
            $assignmentService->assignLead($lead->refresh(), 'intake');
        }

        $appointment = null;

        if ($intent === 'book_demo' && $appointmentBookingService instanceof AppointmentBookingService) {
            $appointment = $appointmentBookingService->bookForLead(
                lead: $lead->refresh(),
                payload: [
                    'source' => 'portal',
                    'intent' => 'book_demo',
                    'starts_at' => $data['preferred_at'] ?? data_get($portalMeta, 'booking.preferred_at'),
                    'timezone' => data_get($portalMeta, 'booking.timezone'),
                    'channel' => data_get($portalMeta, 'booking.channel'),
                    'description' => data_get($portalMeta, 'booking.message'),
                    'duration_minutes' => data_get($portalSettings, 'booking.default_duration_minutes'),
                    'deal_stage_on_booking' => data_get($portalSettings, 'booking.deal_stage_on_booking'),
                ],
                actorId: null,
            );

            $lead = $lead->refresh();
        }

        if ($portalRequest instanceof PortalRequest) {
            $portalRequest->forceFill([
                'status' => $appointment instanceof Appointment ? 'qualified' : 'in_progress',
                'assigned_to' => $lead->owner_id,
            ])->save();
        }

        $ttlDays = max(1, (int) data_get($portalSettings, 'tracking_token_ttl_days', config('portal.tracking_token_ttl_days', 180)));
        $expiresAt = now()->addDays($ttlDays);

        $trackingToken = $portalTrackingToken->make(
            tenantId: (int) $lead->tenant_id,
            leadId: (int) $lead->id,
            intent: $intent,
            expiresAt: $expiresAt,
        );

        $meta = is_array($lead->meta) ? $lead->meta : [];
        data_set($meta, 'portal.tracking_token_issued_at', now()->toIso8601String());
        data_set($meta, 'portal.tracking_intent', $intent);
        $lead->forceFill(['meta' => $meta])->save();

        $eventService->emit(
            eventName: $eventName,
            tenantId: (int) $lead->tenant_id,
            subjectType: Lead::class,
            subjectId: (int) $lead->id,
            payload: [
                'source' => $lead->source,
                'intent' => $intent,
                'tenant_resolution_source' => $request->attributes->get('tenant_resolution_source'),
                'brand_id' => $lead->brand_id,
                'appointment_id' => $appointment?->id,
            ],
        );

        $preference = $consentService->ensureLeadPreference($lead->refresh());
        $preferenceUrl = route('public.preferences.show', ['token' => $preference->token]);
        $statusUrl = route('public.portal.status', ['token' => $trackingToken]);

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
            'message' => $successMessage,
            'lead' => $lead->refresh()->load('tags'),
            'portal_request_id' => $portalRequest instanceof PortalRequest ? (int) $portalRequest->id : null,
            'brand' => $brand?->only(['id', 'name', 'slug', 'landing_domain']),
            'appointment' => $appointment instanceof Appointment ? $this->mapAppointmentForResponse($appointment) : null,
            'tracking_token' => $trackingToken,
            'status_url' => $statusUrl,
            'tracking_expires_at' => $expiresAt->toIso8601String(),
            'unsubscribe_url' => $unsubscribeUrl,
            'preferences_url' => $preferenceUrl,
        ], 201);
    }

    /**
     * Merge tenant portal settings with defaults.
     *
     * @return array<string, mixed>
     */
    private function portalSettingsForTenant(Tenant $tenant): array
    {
        $stored = is_array(data_get($tenant->settings, 'portal')) ? data_get($tenant->settings, 'portal') : [];
        $defaultChannels = collect((array) data_get(config('portal.booking', []), 'allowed_channels', []))
            ->map(static fn (mixed $channel): string => trim(mb_strtolower((string) $channel)))
            ->filter()
            ->values()
            ->all();

        $storedChannels = collect((array) data_get($stored, 'booking.allowed_channels', []))
            ->map(static fn (mixed $channel): string => trim(mb_strtolower((string) $channel)))
            ->filter()
            ->values()
            ->all();

        $defaultTags = $this->normalizeTags((array) config('portal.default_tags', ['portal']));
        $storedTags = $this->normalizeTags((array) data_get($stored, 'default_tags', []));

        return [
            'enabled' => data_get($stored, 'enabled') !== null
                ? (bool) data_get($stored, 'enabled')
                : (bool) config('portal.enabled', true),
            'headline' => is_string(data_get($stored, 'headline'))
                ? trim((string) data_get($stored, 'headline'))
                : (string) config('portal.headline', 'Talk to our team'),
            'subtitle' => is_string(data_get($stored, 'subtitle'))
                ? trim((string) data_get($stored, 'subtitle'))
                : (string) config('portal.subtitle', ''),
            'support_email' => is_string(data_get($stored, 'support_email'))
                ? trim((string) data_get($stored, 'support_email'))
                : null,
            'support_phone' => is_string(data_get($stored, 'support_phone'))
                ? trim((string) data_get($stored, 'support_phone'))
                : null,
            'privacy_url' => is_string(data_get($stored, 'privacy_url'))
                ? trim((string) data_get($stored, 'privacy_url'))
                : null,
            'terms_url' => is_string(data_get($stored, 'terms_url'))
                ? trim((string) data_get($stored, 'terms_url'))
                : null,
            'source_prefix' => is_string(data_get($stored, 'source_prefix'))
                ? trim((string) data_get($stored, 'source_prefix'))
                : (string) config('portal.source_prefix', 'portal'),
            'default_status' => is_string(data_get($stored, 'default_status'))
                ? trim((string) data_get($stored, 'default_status'))
                : (string) config('portal.default_status', 'new'),
            'auto_assign' => data_get($stored, 'auto_assign') !== null
                ? (bool) data_get($stored, 'auto_assign')
                : (bool) config('portal.auto_assign', true),
            'default_form_slug' => is_string(data_get($stored, 'default_form_slug'))
                ? trim((string) data_get($stored, 'default_form_slug'))
                : null,
            'default_tags' => $storedTags !== [] ? $storedTags : $defaultTags,
            'tracking_token_ttl_days' => max(
                1,
                (int) (
                    data_get($stored, 'tracking_token_ttl_days')
                    ?? config('portal.tracking_token_ttl_days', 180)
                )
            ),
            'features' => [
                'request_quote' => data_get($stored, 'features.request_quote') !== null
                    ? (bool) data_get($stored, 'features.request_quote')
                    : (bool) data_get(config('portal.features', []), 'request_quote', true),
                'book_demo' => data_get($stored, 'features.book_demo') !== null
                    ? (bool) data_get($stored, 'features.book_demo')
                    : (bool) data_get(config('portal.features', []), 'book_demo', true),
                'upload_docs' => data_get($stored, 'features.upload_docs') !== null
                    ? (bool) data_get($stored, 'features.upload_docs')
                    : (bool) data_get(config('portal.features', []), 'upload_docs', true),
                'track_status' => data_get($stored, 'features.track_status') !== null
                    ? (bool) data_get($stored, 'features.track_status')
                    : (bool) data_get(config('portal.features', []), 'track_status', true),
            ],
            'booking' => [
                'default_timezone' => is_string(data_get($stored, 'booking.default_timezone'))
                    ? trim((string) data_get($stored, 'booking.default_timezone'))
                    : (string) data_get(config('portal.booking', []), 'default_timezone', 'UTC'),
                'allowed_channels' => $storedChannels !== []
                    ? array_values(array_unique($storedChannels))
                    : array_values(array_unique($defaultChannels)),
                'default_duration_minutes' => max(
                    5,
                    (int) (
                        data_get($stored, 'booking.default_duration_minutes')
                        ?? data_get(config('portal.booking', []), 'default_duration_minutes', 30)
                    )
                ),
                'deal_stage_on_booking' => is_string(data_get($stored, 'booking.deal_stage_on_booking'))
                    ? trim((string) data_get($stored, 'booking.deal_stage_on_booking'))
                    : (string) data_get(config('portal.booking', []), 'deal_stage_on_booking', 'demo_booked'),
                'default_link' => (function () use ($stored): ?string {
                    $storedLink = data_get($stored, 'booking.default_link');
                    $defaultLink = is_string($storedLink) ? trim((string) $storedLink) : '';

                    if ($defaultLink !== '') {
                        return $defaultLink;
                    }

                    $configLink = data_get(config('portal.booking', []), 'default_link');

                    if (! is_string($configLink)) {
                        return null;
                    }

                    $configLink = trim($configLink);

                    return $configLink !== '' ? $configLink : null;
                })(),
            ],
        ];
    }

    /**
     * Build portal booking links for agents and teams.
     *
     * @return array{agents: list<array<string, mixed>>, teams: list<array<string, mixed>>}
     */
    private function bookingLinksForTenant(Tenant $tenant): array
    {
        $agents = User::query()
            ->withoutTenancy()
            ->where('tenant_id', (int) $tenant->id)
            ->where('is_super_admin', false)
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'settings'])
            ->map(static function (User $user): ?array {
                $link = data_get($user->settings, 'booking.link');

                if (! is_string($link) || trim($link) === '') {
                    return null;
                }

                return [
                    'id' => (int) $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'booking_link' => trim($link),
                ];
            })
            ->filter()
            ->values()
            ->all();

        $teams = Team::query()
            ->withoutTenancy()
            ->where('tenant_id', (int) $tenant->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'settings'])
            ->map(static function (Team $team): ?array {
                $link = data_get($team->settings, 'booking.link');

                if (! is_string($link) || trim($link) === '') {
                    return null;
                }

                return [
                    'id' => (int) $team->id,
                    'name' => $team->name,
                    'slug' => $team->slug,
                    'booking_link' => trim($link),
                ];
            })
            ->filter()
            ->values()
            ->all();

        return [
            'agents' => $agents,
            'teams' => $teams,
        ];
    }

    private function resolvePortalOwner(Tenant $tenant, mixed $ownerId): ?User
    {
        if (! is_numeric($ownerId) || (int) $ownerId <= 0) {
            return null;
        }

        $owner = User::query()
            ->withoutTenancy()
            ->where('tenant_id', (int) $tenant->id)
            ->where('is_super_admin', false)
            ->whereKey((int) $ownerId)
            ->first();

        if (! $owner instanceof User) {
            abort(422, 'Provided owner_id was not found for tenant.');
        }

        return $owner;
    }

    private function resolvePortalTeam(Tenant $tenant, mixed $teamId): ?Team
    {
        if (! is_numeric($teamId) || (int) $teamId <= 0) {
            return null;
        }

        $team = Team::query()
            ->withoutTenancy()
            ->where('tenant_id', (int) $tenant->id)
            ->where('is_active', true)
            ->whereKey((int) $teamId)
            ->first();

        if (! $team instanceof Team) {
            abort(422, 'Provided team_id was not found for tenant.');
        }

        return $team;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapAppointmentForResponse(Appointment $appointment): array
    {
        return [
            'id' => (int) $appointment->id,
            'lead_id' => $appointment->lead_id !== null ? (int) $appointment->lead_id : null,
            'owner_id' => $appointment->owner_id !== null ? (int) $appointment->owner_id : null,
            'team_id' => $appointment->team_id !== null ? (int) $appointment->team_id : null,
            'source' => $appointment->source,
            'channel' => $appointment->channel,
            'status' => $appointment->status,
            'title' => $appointment->title,
            'description' => $appointment->description,
            'starts_at' => optional($appointment->starts_at)?->toIso8601String(),
            'ends_at' => optional($appointment->ends_at)?->toIso8601String(),
            'timezone' => $appointment->timezone,
            'booking_link' => $appointment->booking_link,
            'meeting_url' => $appointment->meeting_url,
            'external_refs' => is_array($appointment->external_refs) ? $appointment->external_refs : [],
            'meta' => is_array($appointment->meta) ? $appointment->meta : [],
        ];
    }

    /**
     * @param array<int, mixed> $tags
     * @return list<string>
     */
    private function normalizeTags(array $tags): array
    {
        return collect($tags)
            ->map(static fn (mixed $tag): string => trim((string) $tag))
            ->filter(static fn (string $tag): bool => $tag !== '')
            ->unique()
            ->values()
            ->all();
    }
}
