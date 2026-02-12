<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Lead;
use App\Models\Template;
use App\Services\VariableRenderingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TemplateController extends Controller
{
    /**
     * Display paginated templates.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'channel' => ['nullable', Rule::in(['email', 'sms', 'whatsapp'])],
            'is_active' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Template::query();

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

        $templates = $query
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 15))
            ->withQueryString();

        return response()->json($templates);
    }

    /**
     * Store a new template.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $tenantId = $this->resolveTenantIdForWrite($request);
        $payload = $this->validatePayload($request, $tenantId, isUpdate: false);
        $normalized = $this->normalizeByChannel($payload, null);

        $template = Template::query()->withoutTenancy()->create([
            'tenant_id' => $tenantId,
            'name' => $payload['name'],
            'slug' => $payload['slug'] ?? Str::slug($payload['name']),
            'channel' => $payload['channel'],
            'subject' => $normalized['subject'],
            'content' => $normalized['content'],
            'body_text' => $normalized['body_text'],
            'whatsapp_template_name' => $normalized['whatsapp_template_name'],
            'whatsapp_variables' => $normalized['whatsapp_variables'],
            'settings' => $payload['settings'] ?? [],
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

        return response()->json([
            'message' => 'Template created successfully.',
            'template' => $template,
        ], 201);
    }

    /**
     * Display a template.
     */
    public function show(Request $request, Template $template): JsonResponse
    {
        $this->authorizeAdmin($request);

        return response()->json([
            'template' => $template,
        ]);
    }

    /**
     * Update a template.
     */
    public function update(Request $request, Template $template): JsonResponse
    {
        $this->authorizeAdmin($request);

        $payload = $this->validatePayload($request, (int) $template->tenant_id, true, $template);
        $normalized = $this->normalizeByChannel($payload, $template);

        $template->fill([
            'name' => $payload['name'] ?? $template->name,
            'slug' => $payload['slug'] ?? $template->slug,
            'channel' => $payload['channel'] ?? $template->channel,
            'subject' => $normalized['subject'],
            'content' => $normalized['content'],
            'body_text' => $normalized['body_text'],
            'whatsapp_template_name' => $normalized['whatsapp_template_name'],
            'whatsapp_variables' => $normalized['whatsapp_variables'],
            'settings' => $payload['settings'] ?? $template->settings,
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

        return response()->json([
            'message' => 'Template updated successfully.',
            'template' => $template->refresh(),
        ]);
    }

    /**
     * Delete a template.
     */
    public function destroy(Request $request, Template $template): JsonResponse
    {
        $this->authorizeAdmin($request);

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
        VariableRenderingService $renderingService
    ): JsonResponse {
        $this->authorizeAdmin($request);

        $payload = $request->validate([
            'lead_id' => ['nullable', 'integer', 'exists:leads,id'],
            'variables' => ['nullable', 'array'],
        ]);

        $variables = is_array($payload['variables'] ?? null) ? $payload['variables'] : [];

        if (isset($payload['lead_id'])) {
            $lead = Lead::query()
                ->withoutTenancy()
                ->where('tenant_id', $template->tenant_id)
                ->whereKey((int) $payload['lead_id'])
                ->first();

            if ($lead === null) {
                abort(422, 'Provided lead_id does not belong to the template tenant.');
            }

            $variables = array_merge($renderingService->variablesFromLead($lead), $variables);
        }

        $response = [
            'template_id' => $template->id,
            'channel' => $template->channel,
        ];

        if ($template->channel === 'email') {
            $response['rendered'] = [
                'subject' => $renderingService->renderString((string) $template->subject, $variables),
                'html' => $renderingService->renderString((string) $template->content, $variables),
            ];
        } elseif ($template->channel === 'sms') {
            $source = $template->body_text ?? $template->content ?? '';
            $response['rendered'] = [
                'text' => $renderingService->renderString((string) $source, $variables),
            ];
        } else {
            $response['rendered'] = [
                'template_name' => $renderingService->renderString(
                    (string) $template->whatsapp_template_name,
                    $variables
                ),
                'variables' => $renderingService->renderArray(
                    is_array($template->whatsapp_variables) ? $template->whatsapp_variables : [],
                    $variables
                ),
            ];
        }

        return response()->json($response);
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
            'subject' => ['sometimes', 'nullable', 'string', 'max:255'],
            'html' => ['sometimes', 'nullable', 'string'],
            'text' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'whatsapp_template_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'whatsapp_variables' => ['sometimes', 'nullable', 'array'],
            'settings' => ['sometimes', 'nullable', 'array'],
            'is_active' => ['sometimes', 'boolean'],
        ];

        if (! $isUpdate) {
            $rules['name'][] = 'required';
            $rules['channel'][] = 'required';
        }

        $payload = $request->validate($rules);

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
            $hasTemplateName = array_key_exists('whatsapp_template_name', $payload)
                || ($template?->channel === 'whatsapp' && filled($template->whatsapp_template_name));
            $hasVariables = array_key_exists('whatsapp_variables', $payload)
                || ($template?->channel === 'whatsapp' && is_array($template->whatsapp_variables));

            if (! $hasTemplateName) {
                abort(422, 'whatsapp_template_name is required for WhatsApp templates.');
            }

            if (! $hasVariables) {
                abort(422, 'whatsapp_variables is required for WhatsApp templates.');
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
     * Ensure caller has admin permission.
     */
    private function authorizeAdmin(Request $request): void
    {
        $user = $request->user();

        if (! $user || ! $user->isAdmin()) {
            abort(403, 'Admin permissions are required.');
        }
    }
}
