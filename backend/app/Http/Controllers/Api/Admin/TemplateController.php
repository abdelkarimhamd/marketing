<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Attachment;
use App\Models\Brand;
use App\Models\Lead;
use App\Models\Template;
use App\Services\CampaignEngineService;
use App\Services\RealtimeEventService;
use App\Services\VariableRenderingService;
use App\Services\WorkflowVersioningService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TemplateController extends Controller
{
    /**
     * @var list<string>
     */
    private const WHATSAPP_MESSAGE_TYPES = [
        'template',
        'text',
        'image',
        'video',
        'audio',
        'document',
        'catalog',
        'catalog_list',
        'carousel',
    ];

    /**
     * @var list<string>
     */
    private const WHATSAPP_MEDIA_TYPES = ['image', 'video', 'audio', 'document'];

    /**
     * Display paginated templates.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'templates.view');

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'channel' => ['nullable', Rule::in(['email', 'sms', 'whatsapp'])],
            'is_active' => ['nullable', 'boolean'],
            'brand_id' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Template::query()->with('brand:id,name,slug');

        if (! empty($filters['search'])) {
            $search = $filters['search'];

            $query->where(function (Builder $builder) use ($search): void {
                $builder
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%")
                    ->orWhere('subject', 'like', "%{$search}%")
                    ->orWhere('whatsapp_template_name', 'like', "%{$search}%");
            });
        }

        if (! empty($filters['channel'])) {
            $query->where('channel', $filters['channel']);
        }

        if (array_key_exists('is_active', $filters)) {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        if (array_key_exists('brand_id', $filters) && is_numeric($filters['brand_id'])) {
            $query->where('brand_id', (int) $filters['brand_id']);
        }

        $templates = $query
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 15))
            ->withQueryString();

        return response()->json($templates);
    }

    /**
     * Store a new template.
     */
    public function store(
        Request $request,
        WorkflowVersioningService $workflowVersioningService,
        RealtimeEventService $eventService
    ): JsonResponse
    {
        $this->authorizePermission($request, 'templates.create');

        $tenantId = $this->resolveTenantIdForWrite($request);
        $payload = $this->validatePayload($request, $tenantId, isUpdate: false);
        $normalized = $this->normalizeByChannel($payload, null);
        $settings = $this->resolveSettingsPayload($payload, null);

        $template = Template::query()->withoutTenancy()->create([
            'tenant_id' => $tenantId,
            'brand_id' => $payload['brand_id'] ?? null,
            'name' => $payload['name'],
            'slug' => $payload['slug'] ?? Str::slug($payload['name']),
            'channel' => $payload['channel'],
            'subject' => $normalized['subject'],
            'content' => $normalized['content'],
            'body_text' => $normalized['body_text'],
            'whatsapp_template_name' => $normalized['whatsapp_template_name'],
            'whatsapp_variables' => $normalized['whatsapp_variables'],
            'settings' => $settings,
            'is_active' => $payload['is_active'] ?? true,
        ]);

        Activity::query()->withoutTenancy()->create([
            'tenant_id' => $tenantId,
            'actor_id' => optional($request->user())->id,
            'type' => 'template.admin.created',
            'subject_type' => Template::class,
            'subject_id' => $template->id,
            'description' => 'Template created from admin module.',
        ]);

        $workflowVersioningService->snapshot(
            subject: $template,
            tenantId: (int) $template->tenant_id,
            createdBy: $request->user()?->id,
            status: 'draft',
        );

        $eventService->emit(
            eventName: 'template.created',
            tenantId: (int) $template->tenant_id,
            subjectType: Template::class,
            subjectId: (int) $template->id,
            payload: [
                'channel' => $template->channel,
            ],
        );

        return response()->json([
            'message' => 'Template created successfully.',
            'template' => $template->load('brand:id,name,slug'),
        ], 201);
    }

    /**
     * Display a template.
     */
    public function show(Request $request, Template $template): JsonResponse
    {
        $this->authorizePermission($request, 'templates.view');

        return response()->json([
            'template' => $template->load('brand:id,name,slug'),
        ]);
    }

    /**
     * Update a template.
     */
    public function update(
        Request $request,
        Template $template,
        WorkflowVersioningService $workflowVersioningService,
        RealtimeEventService $eventService
    ): JsonResponse
    {
        $this->authorizePermission($request, 'templates.update');

        $payload = $this->validatePayload($request, (int) $template->tenant_id, true, $template);
        $normalized = $this->normalizeByChannel($payload, $template);
        $settings = $this->resolveSettingsPayload($payload, $template);

        $template->fill([
            'brand_id' => array_key_exists('brand_id', $payload) ? $payload['brand_id'] : $template->brand_id,
            'name' => $payload['name'] ?? $template->name,
            'slug' => $payload['slug'] ?? $template->slug,
            'channel' => $payload['channel'] ?? $template->channel,
            'subject' => $normalized['subject'],
            'content' => $normalized['content'],
            'body_text' => $normalized['body_text'],
            'whatsapp_template_name' => $normalized['whatsapp_template_name'],
            'whatsapp_variables' => $normalized['whatsapp_variables'],
            'settings' => $settings,
            'is_active' => $payload['is_active'] ?? $template->is_active,
        ]);

        $template->save();

        Activity::query()->withoutTenancy()->create([
            'tenant_id' => $template->tenant_id,
            'actor_id' => optional($request->user())->id,
            'type' => 'template.admin.updated',
            'subject_type' => Template::class,
            'subject_id' => $template->id,
            'description' => 'Template updated from admin module.',
        ]);

        $workflowVersioningService->snapshot(
            subject: $template->refresh(),
            tenantId: (int) $template->tenant_id,
            createdBy: $request->user()?->id,
            status: 'draft',
        );

        $eventService->emit(
            eventName: 'template.updated',
            tenantId: (int) $template->tenant_id,
            subjectType: Template::class,
            subjectId: (int) $template->id,
            payload: [
                'channel' => $template->channel,
            ],
        );

        return response()->json([
            'message' => 'Template updated successfully.',
            'template' => $template->refresh()->load('brand:id,name,slug'),
        ]);
    }

    /**
     * Delete a template.
     */
    public function destroy(Request $request, Template $template): JsonResponse
    {
        $this->authorizePermission($request, 'templates.delete');

        $template->delete();

        Activity::query()->withoutTenancy()->create([
            'tenant_id' => $template->tenant_id,
            'actor_id' => optional($request->user())->id,
            'type' => 'template.admin.deleted',
            'subject_type' => Template::class,
            'subject_id' => $template->id,
            'description' => 'Template deleted from admin module.',
        ]);

        return response()->json([
            'message' => 'Template deleted successfully.',
        ]);
    }

    /**
     * Render template content with variables.
     */
    public function render(
        Request $request,
        Template $template,
        VariableRenderingService $renderingService,
        CampaignEngineService $campaignEngineService
    ): JsonResponse {
        $this->authorizePermission($request, 'templates.send');

        $payload = $request->validate([
            'lead_id' => ['nullable', 'integer', 'min:1'],
            'lead_ids' => ['nullable', 'array', 'max:50'],
            'lead_ids.*' => ['integer', 'distinct', 'min:1'],
            'variables' => ['nullable', 'array'],
        ]);

        $baseVariables = is_array($payload['variables'] ?? null) ? $payload['variables'] : [];
        $leadIds = collect(is_array($payload['lead_ids'] ?? null) ? $payload['lead_ids'] : [])
            ->filter(fn ($id): bool => is_numeric($id) && (int) $id > 0)
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        if (isset($payload['lead_id'])) {
            $leadIds = $leadIds->prepend((int) $payload['lead_id'])->unique()->values();
        }

        $leads = collect();

        if ($leadIds->isNotEmpty()) {
            $leads = Lead::query()
                ->withoutTenancy()
                ->where('tenant_id', $template->tenant_id)
                ->whereIn('id', $leadIds->all())
                ->get()
                ->keyBy('id');

            if ($leads->count() !== $leadIds->count()) {
                abort(422, 'Provided lead_id/lead_ids include records outside the template tenant or not found.');
            }
        }

        $response = [
            'template_id' => $template->id,
            'brand_id' => $template->brand_id,
            'channel' => $template->channel,
        ];

        if ($leadIds->isNotEmpty()) {
            $previews = $leadIds
                ->map(function (int $leadId) use ($leads, $baseVariables, $template, $renderingService, $campaignEngineService): array {
                    $lead = $leads->get($leadId);
                    $variables = $baseVariables;

                    if ($lead instanceof Lead) {
                        $variables = array_merge($renderingService->variablesFromLead($lead), $variables);
                    }

                    $preview = $this->buildRenderedPreview(
                        template: $template,
                        variables: $variables,
                        renderingService: $renderingService,
                        campaignEngineService: $campaignEngineService
                    );

                    return [
                        'lead_id' => $leadId,
                        'rendered' => $preview['rendered'],
                        'personalization' => $preview['personalization'],
                    ];
                })
                ->values()
                ->all();

            if (count($previews) > 1 || array_key_exists('lead_ids', $payload)) {
                $response['previews'] = $previews;

                return response()->json($response);
            }

            $preview = $previews[0] ?? null;

            if (is_array($preview)) {
                $response['lead_id'] = $preview['lead_id'] ?? null;
                $response['rendered'] = $preview['rendered'] ?? [];
                $response['personalization'] = $preview['personalization'] ?? $renderingService->emptyRenderMeta($baseVariables);
            }

            return response()->json($response);
        }

        $preview = $this->buildRenderedPreview(
            template: $template,
            variables: $baseVariables,
            renderingService: $renderingService,
            campaignEngineService: $campaignEngineService
        );
        $response['rendered'] = $preview['rendered'];
        $response['personalization'] = $preview['personalization'];

        return response()->json($response);
    }

    /**
     * Render one preview variant and aggregate personalization metadata.
     *
     * @param array<string, mixed> $variables
     * @return array{rendered: array<string, mixed>, personalization: array<string, mixed>}
     */
    private function buildRenderedPreview(
        Template $template,
        array $variables,
        VariableRenderingService $renderingService,
        CampaignEngineService $campaignEngineService
    ): array {
        $personalization = $renderingService->emptyRenderMeta($variables);

        if ($template->channel === 'email') {
            $subject = $renderingService->renderStringWithMeta((string) $template->subject, $variables);
            $html = $renderingService->renderStringWithMeta((string) $template->content, $variables);

            $personalization = $renderingService->mergeRenderMeta($personalization, is_array($subject['meta'] ?? null) ? $subject['meta'] : []);
            $personalization = $renderingService->mergeRenderMeta($personalization, is_array($html['meta'] ?? null) ? $html['meta'] : []);

            return [
                'rendered' => [
                    'subject' => $subject['rendered'] ?? '',
                    'html' => $html['rendered'] ?? '',
                ],
                'personalization' => $personalization,
            ];
        }

        if ($template->channel === 'sms') {
            $source = (string) ($template->body_text ?? $template->content ?? '');
            $text = $renderingService->renderStringWithMeta($source, $variables);

            $personalization = $renderingService->mergeRenderMeta($personalization, is_array($text['meta'] ?? null) ? $text['meta'] : []);

            return [
                'rendered' => [
                    'text' => $text['rendered'] ?? '',
                ],
                'personalization' => $personalization,
            ];
        }

        $whatsappRendered = $campaignEngineService->renderWhatsAppPayload($template, $variables, $renderingService);
        $whatsappMeta = is_array($whatsappRendered['meta'] ?? null) ? $whatsappRendered['meta'] : [];

        if (filled($template->whatsapp_template_name)) {
            $templateName = $renderingService->renderStringWithMeta((string) $template->whatsapp_template_name, $variables);
            $personalization = $renderingService->mergeRenderMeta(
                $personalization,
                is_array($templateName['meta'] ?? null) ? $templateName['meta'] : []
            );
        }

        $whatsappVariables = $template->whatsapp_variables;
        if (is_array($whatsappVariables)) {
            $variablesMeta = $renderingService->renderArrayWithMeta($whatsappVariables, $variables);
            $personalization = $renderingService->mergeRenderMeta(
                $personalization,
                is_array($variablesMeta['meta'] ?? null) ? $variablesMeta['meta'] : []
            );
        }

        $settings = is_array($template->settings) ? $template->settings : [];
        $whatsappSettings = Arr::get($settings, 'whatsapp', []);
        if (is_array($whatsappSettings)) {
            $settingsMeta = $renderingService->renderArrayWithMeta($whatsappSettings, $variables);
            $personalization = $renderingService->mergeRenderMeta(
                $personalization,
                is_array($settingsMeta['meta'] ?? null) ? $settingsMeta['meta'] : []
            );
        }

        return [
            'rendered' => array_filter([
                'message_type' => (string) ($whatsappMeta['message_type'] ?? 'template'),
                'text' => $whatsappRendered['body'] ?? null,
                'template_name' => $whatsappMeta['template_name'] ?? null,
                'variables' => $whatsappMeta['variables'] ?? null,
                'language' => $whatsappMeta['language'] ?? null,
                'components' => $whatsappMeta['components'] ?? null,
                'media' => $whatsappMeta['media'] ?? null,
                'catalog' => $whatsappMeta['catalog'] ?? null,
                'carousel' => $whatsappMeta['carousel'] ?? null,
            ], static fn (mixed $value): bool => $value !== null && $value !== []),
            'personalization' => $personalization,
        ];
    }

    /**
     * Validate template payload.
     *
     * @return array<string, mixed>
     */
    private function validatePayload(
        Request $request,
        int $tenantId,
        bool $isUpdate = false,
        ?Template $template = null
    ): array {
        $slugRule = Rule::unique('templates', 'slug')
            ->where(fn ($builder) => $builder->where('tenant_id', $tenantId));

        if ($template !== null) {
            $slugRule->ignore($template->id);
        }

        $rules = [
            'name' => ['sometimes', 'string', 'max:255'],
            'slug' => ['sometimes', 'string', 'max:255', $slugRule],
            'channel' => ['sometimes', Rule::in(['email', 'sms', 'whatsapp'])],
            'brand_id' => ['sometimes', 'nullable', 'integer', 'min:1', 'exists:brands,id'],
            'subject' => ['sometimes', 'nullable', 'string', 'max:255'],
            'html' => ['sometimes', 'nullable', 'string'],
            'text' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'whatsapp_template_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'whatsapp_variables' => ['sometimes', 'nullable', 'array'],
            'settings' => ['sometimes', 'nullable', 'array'],
            'settings.whatsapp' => ['sometimes', 'array'],
            'settings.whatsapp.message_type' => ['sometimes', Rule::in(self::WHATSAPP_MESSAGE_TYPES)],
            'settings.whatsapp.language' => ['sometimes', 'nullable', 'string', 'max:20'],
            'settings.whatsapp.text' => ['sometimes', 'nullable', 'string', 'max:4096'],
            'settings.whatsapp.components' => ['sometimes', 'array'],
            'settings.whatsapp.media' => ['sometimes', 'array'],
            'settings.whatsapp.media.attachment_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'settings.whatsapp.media.link' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'settings.whatsapp.media.caption' => ['sometimes', 'nullable', 'string', 'max:1024'],
            'settings.whatsapp.media.filename' => ['sometimes', 'nullable', 'string', 'max:255'],
            'settings.whatsapp.media.provider_media_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'settings.whatsapp.catalog' => ['sometimes', 'array'],
            'settings.whatsapp.catalog.catalog_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'settings.whatsapp.catalog.product_retailer_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'settings.whatsapp.catalog.body' => ['sometimes', 'nullable', 'string', 'max:1024'],
            'settings.whatsapp.catalog.header' => ['sometimes', 'nullable', 'string', 'max:255'],
            'settings.whatsapp.catalog.footer' => ['sometimes', 'nullable', 'string', 'max:255'],
            'settings.whatsapp.catalog.sections' => ['sometimes', 'array'],
            'settings.whatsapp.catalog.sections.*' => ['sometimes', 'array'],
            'settings.whatsapp.catalog.sections.*.title' => ['sometimes', 'nullable', 'string', 'max:120'],
            'settings.whatsapp.catalog.sections.*.items' => ['sometimes', 'array'],
            'settings.whatsapp.catalog.sections.*.items.*' => ['sometimes', 'nullable', 'string', 'max:255'],
            'settings.whatsapp.catalog.sections.*.product_items' => ['sometimes', 'array'],
            'settings.whatsapp.catalog.sections.*.product_items.*.product_retailer_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'settings.whatsapp.carousel' => ['sometimes', 'array'],
            'settings.whatsapp.carousel.body' => ['sometimes', 'nullable', 'string', 'max:1024'],
            'settings.whatsapp.carousel.header' => ['sometimes', 'nullable', 'string', 'max:255'],
            'settings.whatsapp.carousel.footer' => ['sometimes', 'nullable', 'string', 'max:255'],
            'settings.whatsapp.carousel.button_text' => ['sometimes', 'nullable', 'string', 'max:120'],
            'settings.whatsapp.carousel.section_title' => ['sometimes', 'nullable', 'string', 'max:120'],
            'settings.whatsapp.carousel.cards' => ['sometimes', 'array'],
            'settings.whatsapp.carousel.cards.*' => ['sometimes', 'array'],
            'settings.whatsapp.carousel.cards.*.id' => ['sometimes', 'nullable', 'string', 'max:120'],
            'settings.whatsapp.carousel.cards.*.title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'settings.whatsapp.carousel.cards.*.name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'settings.whatsapp.carousel.cards.*.description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'settings.whatsapp.carousel.cards.*.body' => ['sometimes', 'nullable', 'string', 'max:500'],
            'settings.whatsapp.carousel.cards.*.media' => ['sometimes', 'array'],
            'settings.whatsapp.carousel.cards.*.media.attachment_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'settings.whatsapp.carousel.cards.*.media.link' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'settings.whatsapp.carousel.cards.*.media.caption' => ['sometimes', 'nullable', 'string', 'max:1024'],
            'settings.whatsapp.carousel.cards.*.media.filename' => ['sometimes', 'nullable', 'string', 'max:255'],
            'settings.whatsapp.carousel.cards.*.media.provider_media_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];

        if (! $isUpdate) {
            $rules['name'][] = 'required';
            $rules['channel'][] = 'required';
        }

        $payload = $request->validate($rules);

        if (array_key_exists('brand_id', $payload) && $payload['brand_id'] !== null) {
            $exists = Brand::query()
                ->withoutTenancy()
                ->where('tenant_id', $tenantId)
                ->whereKey((int) $payload['brand_id'])
                ->exists();

            if (! $exists) {
                abort(422, 'Provided brand_id does not belong to the active tenant.');
            }
        }

        $channel = (string) ($payload['channel'] ?? $template?->channel ?? '');

        if ($channel === 'email') {
            $hasSubject = array_key_exists('subject', $payload)
                || ($template?->channel === 'email' && filled($template->subject));
            $hasHtml = array_key_exists('html', $payload)
                || ($template?->channel === 'email' && filled($template->content));

            if (! $hasSubject) {
                abort(422, 'subject is required for email templates.');
            }

            if (! $hasHtml) {
                abort(422, 'html is required for email templates.');
            }
        }

        if ($channel === 'sms') {
            $hasText = array_key_exists('text', $payload)
                || ($template?->channel === 'sms' && filled($template->body_text ?? $template->content));

            if (! $hasText) {
                abort(422, 'text is required for SMS templates.');
            }
        }

        if ($channel === 'whatsapp') {
            $whatsappSettings = $this->resolveWhatsAppSettings($payload, $template, $channel);
            $messageType = $this->resolveWhatsAppMessageType($whatsappSettings, $payload, $template);

            if ($messageType === 'template') {
                $hasTemplateName = array_key_exists('whatsapp_template_name', $payload)
                    || ($template?->channel === 'whatsapp' && filled($template->whatsapp_template_name));
                $hasVariables = array_key_exists('whatsapp_variables', $payload)
                    || ($template?->channel === 'whatsapp' && is_array($template->whatsapp_variables));

                if (! $hasTemplateName) {
                    abort(422, 'whatsapp_template_name is required for WhatsApp template messages.');
                }

                if (! $hasVariables) {
                    abort(422, 'whatsapp_variables is required for WhatsApp template messages.');
                }
            } elseif ($messageType === 'text') {
                $text = trim((string) ($whatsappSettings['text'] ?? ''));

                if ($text === '') {
                    abort(422, 'settings.whatsapp.text is required for WhatsApp text messages.');
                }
            } elseif (in_array($messageType, self::WHATSAPP_MEDIA_TYPES, true)) {
                $link = trim((string) data_get($whatsappSettings, 'media.link', ''));
                $attachmentId = data_get($whatsappSettings, 'media.attachment_id');

                if (is_numeric($attachmentId)) {
                    $this->assertMediaAttachmentInTenant($tenantId, (int) $attachmentId);
                }

                if ($link === '' && ! is_numeric($attachmentId)) {
                    abort(
                        422,
                        'settings.whatsapp.media.link or settings.whatsapp.media.attachment_id is required for WhatsApp media messages.'
                    );
                }
            } elseif ($messageType === 'catalog') {
                $catalogId = trim((string) data_get($whatsappSettings, 'catalog.catalog_id', ''));
                $productRetailerId = trim((string) data_get($whatsappSettings, 'catalog.product_retailer_id', ''));

                if ($catalogId === '' || $productRetailerId === '') {
                    abort(
                        422,
                        'settings.whatsapp.catalog.catalog_id and settings.whatsapp.catalog.product_retailer_id are required for WhatsApp catalog messages.'
                    );
                }
            } elseif ($messageType === 'catalog_list') {
                $catalogId = trim((string) data_get($whatsappSettings, 'catalog.catalog_id', ''));
                $sections = data_get($whatsappSettings, 'catalog.sections', []);

                if ($catalogId === '' || ! is_array($sections) || $sections === []) {
                    abort(
                        422,
                        'settings.whatsapp.catalog.catalog_id and settings.whatsapp.catalog.sections are required for WhatsApp catalog list messages.'
                    );
                }
            } elseif ($messageType === 'carousel') {
                $cards = data_get($whatsappSettings, 'carousel.cards', []);

                if (! is_array($cards) || $cards === []) {
                    abort(422, 'settings.whatsapp.carousel.cards is required for WhatsApp carousel messages.');
                }

                foreach ($cards as $card) {
                    $attachmentId = data_get($card, 'media.attachment_id');

                    if (is_numeric($attachmentId)) {
                        $this->assertMediaAttachmentInTenant($tenantId, (int) $attachmentId);
                    }
                }
            }
        }

        return $payload;
    }

    /**
     * Normalize payload by template channel.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalizeByChannel(array $payload, ?Template $existing): array
    {
        $channel = (string) ($payload['channel'] ?? $existing?->channel ?? 'email');

        $normalized = [
            'subject' => $payload['subject'] ?? $existing?->subject,
            'content' => $existing?->content,
            'body_text' => $existing?->body_text,
            'whatsapp_template_name' => $payload['whatsapp_template_name'] ?? $existing?->whatsapp_template_name,
            'whatsapp_variables' => Arr::exists($payload, 'whatsapp_variables')
                ? $payload['whatsapp_variables']
                : $existing?->whatsapp_variables,
        ];

        if ($channel === 'email') {
            $normalized['content'] = $payload['html'] ?? $existing?->content;
            $normalized['body_text'] = null;
            $normalized['whatsapp_template_name'] = null;
            $normalized['whatsapp_variables'] = null;
        } elseif ($channel === 'sms') {
            $text = $payload['text'] ?? $existing?->body_text ?? $existing?->content;
            $normalized['subject'] = null;
            $normalized['content'] = $text;
            $normalized['body_text'] = $text;
            $normalized['whatsapp_template_name'] = null;
            $normalized['whatsapp_variables'] = null;
        } elseif ($channel === 'whatsapp') {
            $normalized['subject'] = null;
            $normalized['content'] = '';
            $normalized['body_text'] = null;
        }

        return $normalized;
    }

    /**
     * Resolve tenant id for write operations.
     */
    private function resolveTenantIdForWrite(Request $request): int
    {
        $tenantId = $request->attributes->get('tenant_id');

        if (is_int($tenantId) && $tenantId > 0) {
            return $tenantId;
        }

        if (is_numeric($request->input('tenant_id')) && (int) $request->input('tenant_id') > 0) {
            return (int) $request->input('tenant_id');
        }

        abort(422, 'Tenant context is required for this operation. Select/supply tenant_id first.');
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function resolveSettingsPayload(array $payload, ?Template $existing): array
    {
        $existingSettings = is_array($existing?->settings) ? $existing->settings : [];
        $settings = $existingSettings;

        if (Arr::exists($payload, 'settings')) {
            if ($payload['settings'] === null) {
                $settings = [];
            } else {
                $incoming = is_array($payload['settings'] ?? null) ? $payload['settings'] : [];
                $settings = $this->mergeSettings($existingSettings, $incoming);
            }
        }

        $channel = (string) ($payload['channel'] ?? $existing?->channel ?? '');

        if ($channel === 'whatsapp' && Arr::exists($payload, 'text')) {
            Arr::set($settings, 'whatsapp.text', $payload['text']);
        }

        return $settings;
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $incoming
     * @return array<string, mixed>
     */
    private function mergeSettings(array $base, array $incoming): array
    {
        return array_replace_recursive($base, $incoming);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function resolveWhatsAppSettings(array $payload, ?Template $existing, string $channel): array
    {
        if ($channel !== 'whatsapp') {
            return [];
        }

        $settings = $this->resolveSettingsPayload($payload, $existing);
        $whatsapp = Arr::get($settings, 'whatsapp', []);

        return is_array($whatsapp) ? $whatsapp : [];
    }

    /**
     * @param array<string, mixed> $whatsappSettings
     * @param array<string, mixed> $payload
     */
    private function resolveWhatsAppMessageType(array $whatsappSettings, array $payload, ?Template $template): string
    {
        $messageType = mb_strtolower(trim((string) ($whatsappSettings['message_type'] ?? '')));

        if ($messageType === '') {
            $templateName = $payload['whatsapp_template_name'] ?? $template?->whatsapp_template_name;
            $messageType = filled($templateName) ? 'template' : 'text';
        }

        if (! in_array($messageType, self::WHATSAPP_MESSAGE_TYPES, true)) {
            return 'template';
        }

        return $messageType;
    }

    private function assertMediaAttachmentInTenant(int $tenantId, int $attachmentId): void
    {
        $exists = Attachment::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->whereKey($attachmentId)
            ->where('entity_type', 'media_library')
            ->exists();

        if (! $exists) {
            abort(
                422,
                'Provided settings.whatsapp media attachment_id is not found in the tenant media library.'
            );
        }
    }

}
