<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Campaign;
use App\Models\CampaignStep;
use App\Models\Segment;
use App\Models\Team;
use App\Models\Template;
use App\Services\CampaignEngineService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CampaignController extends Controller
{
    /**
     * Display paginated campaigns.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in([
                Campaign::STATUS_DRAFT,
                Campaign::STATUS_SCHEDULED,
                Campaign::STATUS_RUNNING,
                Campaign::STATUS_COMPLETED,
                Campaign::STATUS_PAUSED,
            ])],
            'campaign_type' => ['nullable', Rule::in([
                Campaign::TYPE_BROADCAST,
                Campaign::TYPE_SCHEDULED,
                Campaign::TYPE_DRIP,
            ])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Campaign::query()
            ->with([
                'segment:id,name,slug',
                'template:id,name,slug,channel',
                'team:id,name',
                'creator:id,name,email',
                'steps:id,tenant_id,campaign_id,name,step_order,delay_minutes,is_active',
            ]);

        if (! empty($filters['search'])) {
            $search = $filters['search'];

            $query->where(function (Builder $builder) use ($search): void {
                $builder
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['campaign_type'])) {
            $query->where('campaign_type', $filters['campaign_type']);
        }

        $campaigns = $query
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 15))
            ->withQueryString();

        return response()->json($campaigns);
    }

    /**
     * Store a campaign draft.
     */
    public function store(Request $request, CampaignEngineService $engine): JsonResponse
    {
        $this->authorizeAdmin($request);

        $tenantId = $this->resolveTenantIdForWrite($request);
        $payload = $this->validatePayload($request, $tenantId, false);
        $this->validateTenantReferences($tenantId, $payload);

        $campaign = DB::transaction(function () use ($request, $tenantId, $payload, $engine): Campaign {
            $template = isset($payload['template_id'])
                ? Template::query()->withoutTenancy()->where('tenant_id', $tenantId)->whereKey($payload['template_id'])->first()
                : null;

            $campaign = Campaign::query()->withoutTenancy()->create([
                'tenant_id' => $tenantId,
                'segment_id' => $payload['segment_id'] ?? null,
                'template_id' => $payload['template_id'] ?? null,
                'team_id' => $payload['team_id'] ?? null,
                'created_by' => optional($request->user())->id,
                'name' => $payload['name'],
                'slug' => $payload['slug'] ?? Str::slug($payload['name']),
                'description' => $payload['description'] ?? null,
                'channel' => $payload['channel'] ?? $template?->channel ?? 'email',
                'campaign_type' => $payload['campaign_type'] ?? Campaign::TYPE_BROADCAST,
                'status' => Campaign::STATUS_DRAFT,
                'start_at' => $payload['start_at'] ?? null,
                'end_at' => $payload['end_at'] ?? null,
                'settings' => $this->buildSettings($payload, []),
                'metrics' => [],
            ]);

            if ($campaign->isDrip()) {
                $engine->prepareDripSteps($campaign, $payload['drip_steps'] ?? null);
            }

            Activity::query()->withoutTenancy()->create([
                'tenant_id' => $tenantId,
                'actor_id' => optional($request->user())->id,
                'type' => 'campaign.admin.created',
                'subject_type' => Campaign::class,
                'subject_id' => $campaign->id,
                'description' => 'Campaign created from admin module.',
            ]);

            return $campaign;
        });

        return response()->json([
            'message' => 'Campaign created successfully.',
            'campaign' => $this->loadCampaign($campaign),
        ], 201);
    }

    /**
     * Show a campaign.
     */
    public function show(Request $request, Campaign $campaign): JsonResponse
    {
        $this->authorizeAdmin($request);

        return response()->json([
            'campaign' => $this->loadCampaign($campaign),
        ]);
    }

    /**
     * Update campaign.
     */
    public function update(Request $request, Campaign $campaign, CampaignEngineService $engine): JsonResponse
    {
        $this->authorizeAdmin($request);

        $payload = $this->validatePayload($request, (int) $campaign->tenant_id, true, $campaign);
        $this->validateTenantReferences((int) $campaign->tenant_id, $payload);

        DB::transaction(function () use ($request, $campaign, $payload, $engine): void {
            $template = null;

            if (isset($payload['template_id'])) {
                $template = Template::query()
                    ->withoutTenancy()
                    ->where('tenant_id', $campaign->tenant_id)
                    ->whereKey($payload['template_id'])
                    ->first();
            }

            $campaign->fill([
                'segment_id' => array_key_exists('segment_id', $payload) ? $payload['segment_id'] : $campaign->segment_id,
                'template_id' => array_key_exists('template_id', $payload) ? $payload['template_id'] : $campaign->template_id,
                'team_id' => array_key_exists('team_id', $payload) ? $payload['team_id'] : $campaign->team_id,
                'name' => $payload['name'] ?? $campaign->name,
                'slug' => $payload['slug'] ?? $campaign->slug,
                'description' => $payload['description'] ?? $campaign->description,
                'channel' => $payload['channel'] ?? $template?->channel ?? $campaign->channel,
                'campaign_type' => $payload['campaign_type'] ?? $campaign->campaign_type,
                'status' => $payload['status'] ?? $campaign->status,
                'start_at' => array_key_exists('start_at', $payload) ? $payload['start_at'] : $campaign->start_at,
                'end_at' => array_key_exists('end_at', $payload) ? $payload['end_at'] : $campaign->end_at,
                'settings' => $this->buildSettings($payload, $campaign->settings),
            ]);

            $campaign->save();

            if ($campaign->isDrip()) {
                if (array_key_exists('drip_steps', $payload)) {
                    $engine->prepareDripSteps($campaign->refresh(), $payload['drip_steps']);
                } elseif ($campaign->steps()->count() === 0) {
                    $engine->prepareDripSteps($campaign->refresh());
                }
            } else {
                CampaignStep::query()
                    ->withoutTenancy()
                    ->where('tenant_id', $campaign->tenant_id)
                    ->where('campaign_id', $campaign->id)
                    ->delete();
            }

            Activity::query()->withoutTenancy()->create([
                'tenant_id' => $campaign->tenant_id,
                'actor_id' => optional($request->user())->id,
                'type' => 'campaign.admin.updated',
                'subject_type' => Campaign::class,
                'subject_id' => $campaign->id,
                'description' => 'Campaign updated from admin module.',
            ]);
        });

        return response()->json([
            'message' => 'Campaign updated successfully.',
            'campaign' => $this->loadCampaign($campaign->refresh()),
        ]);
    }

    /**
     * Delete campaign.
     */
    public function destroy(Request $request, Campaign $campaign): JsonResponse
    {
        $this->authorizeAdmin($request);

        $campaign->delete();

        Activity::query()->withoutTenancy()->create([
            'tenant_id' => $campaign->tenant_id,
            'actor_id' => optional($request->user())->id,
            'type' => 'campaign.admin.deleted',
            'subject_type' => Campaign::class,
            'subject_id' => $campaign->id,
            'description' => 'Campaign deleted from admin module.',
        ]);

        return response()->json([
            'message' => 'Campaign deleted successfully.',
        ]);
    }

    /**
     * Wizard step handler (audience/content/schedule).
     */
    public function wizard(Request $request, Campaign $campaign, CampaignEngineService $engine): JsonResponse
    {
        $this->authorizeAdmin($request);

        $payload = $request->validate([
            'step' => ['required', Rule::in(['audience', 'content', 'schedule'])],
            'segment_id' => ['nullable', 'integer', 'exists:segments,id'],
            'template_id' => ['nullable', 'integer', 'exists:templates,id'],
            'channel' => ['nullable', Rule::in(['email', 'sms', 'whatsapp'])],
            'campaign_type' => ['nullable', Rule::in([
                Campaign::TYPE_BROADCAST,
                Campaign::TYPE_SCHEDULED,
                Campaign::TYPE_DRIP,
            ])],
            'start_at' => ['nullable', 'date'],
            'end_at' => ['nullable', 'date', 'after_or_equal:start_at'],
            'stop_rules' => ['nullable', 'array'],
            'stop_rules.opt_out' => ['nullable', 'boolean'],
            'stop_rules.won_lost' => ['nullable', 'boolean'],
            'stop_rules.replied' => ['nullable', 'boolean'],
            'drip_steps' => ['nullable', 'array', 'max:50'],
            'drip_steps.*.name' => ['nullable', 'string', 'max:255'],
            'drip_steps.*.day' => ['nullable', 'integer', 'min:0', 'max:365'],
            'drip_steps.*.delay_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'drip_steps.*.step_order' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'drip_steps.*.template_id' => ['nullable', 'integer', 'exists:templates,id'],
            'drip_steps.*.channel' => ['nullable', Rule::in(['email', 'sms', 'whatsapp'])],
            'drip_steps.*.is_active' => ['nullable', 'boolean'],
            'drip_steps.*.settings' => ['nullable', 'array'],
        ]);

        $tenantId = (int) $campaign->tenant_id;

        DB::transaction(function () use ($request, $campaign, $payload, $engine, $tenantId): void {
            $step = $payload['step'];
            $settings = is_array($campaign->settings) ? $campaign->settings : [];
            $wizard = is_array($settings['wizard'] ?? null) ? $settings['wizard'] : [];

            if ($step === 'audience') {
                if (! isset($payload['segment_id'])) {
                    abort(422, 'segment_id is required for audience step.');
                }

                $this->validateTenantReferences($tenantId, ['segment_id' => $payload['segment_id']]);
                $campaign->segment_id = $payload['segment_id'];
                $wizard['audience'] = true;
            }

            if ($step === 'content') {
                if (! isset($payload['template_id'])) {
                    abort(422, 'template_id is required for content step.');
                }

                $this->validateTenantReferences($tenantId, ['template_id' => $payload['template_id']]);
                $template = Template::query()
                    ->withoutTenancy()
                    ->where('tenant_id', $tenantId)
                    ->whereKey($payload['template_id'])
                    ->first();

                $campaign->template_id = $payload['template_id'];
                $campaign->channel = $payload['channel'] ?? $template?->channel ?? $campaign->channel;
                $wizard['content'] = true;
            }

            if ($step === 'schedule') {
                $campaignType = $payload['campaign_type'] ?? $campaign->campaign_type;
                $campaign->campaign_type = $campaignType;
                $campaign->start_at = array_key_exists('start_at', $payload) ? $payload['start_at'] : $campaign->start_at;
                $campaign->end_at = array_key_exists('end_at', $payload) ? $payload['end_at'] : $campaign->end_at;

                $settings['stop_rules'] = array_merge(
                    ['opt_out' => true, 'won_lost' => true, 'replied' => true],
                    is_array($payload['stop_rules'] ?? null) ? $payload['stop_rules'] : []
                );
                $wizard['schedule'] = true;
            }

            $settings['wizard'] = $wizard;
            $settings['wizard_completed'] = (bool) ($wizard['audience'] ?? false)
                && (bool) ($wizard['content'] ?? false)
                && (bool) ($wizard['schedule'] ?? false);

            $campaign->settings = $settings;
            $campaign->save();

            if ($step === 'schedule') {
                if ($campaign->isDrip()) {
                    $engine->prepareDripSteps($campaign->refresh(), $payload['drip_steps'] ?? null);
                } else {
                    CampaignStep::query()
                        ->withoutTenancy()
                        ->where('tenant_id', $campaign->tenant_id)
                        ->where('campaign_id', $campaign->id)
                        ->delete();
                }
            }

            Activity::query()->withoutTenancy()->create([
                'tenant_id' => $campaign->tenant_id,
                'actor_id' => optional($request->user())->id,
                'type' => 'campaign.wizard.updated',
                'subject_type' => Campaign::class,
                'subject_id' => $campaign->id,
                'description' => 'Campaign wizard step updated.',
                'properties' => [
                    'step' => $step,
                    'wizard' => $wizard,
                ],
            ]);
        });

        return response()->json([
            'message' => 'Campaign wizard step saved.',
            'campaign' => $this->loadCampaign($campaign->refresh()),
        ]);
    }

    /**
     * Launch campaign processing.
     */
    public function launch(Request $request, Campaign $campaign, CampaignEngineService $engine): JsonResponse
    {
        $this->authorizeAdmin($request);

        $this->ensureCampaignReadyForLaunch($campaign->refresh());

        $engine->launchCampaign($campaign->refresh());

        Activity::query()->withoutTenancy()->create([
            'tenant_id' => $campaign->tenant_id,
            'actor_id' => optional($request->user())->id,
            'type' => 'campaign.launch.requested',
            'subject_type' => Campaign::class,
            'subject_id' => $campaign->id,
            'description' => 'Campaign launch requested from admin module.',
        ]);

        return response()->json([
            'message' => 'Campaign launch queued successfully.',
            'campaign' => $this->loadCampaign($campaign->refresh()),
        ]);
    }

    /**
     * Validate campaign payload.
     *
     * @return array<string, mixed>
     */
    private function validatePayload(
        Request $request,
        int $tenantId,
        bool $isUpdate = false,
        ?Campaign $campaign = null
    ): array {
        $slugRule = Rule::unique('campaigns', 'slug')
            ->where(fn ($builder) => $builder->where('tenant_id', $tenantId));

        if ($campaign !== null) {
            $slugRule->ignore($campaign->id);
        }

        $rules = [
            'name' => ['sometimes', 'string', 'max:255'],
            'slug' => ['sometimes', 'string', 'max:255', $slugRule],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'segment_id' => ['sometimes', 'nullable', 'integer', 'exists:segments,id'],
            'template_id' => ['sometimes', 'nullable', 'integer', 'exists:templates,id'],
            'team_id' => ['sometimes', 'nullable', 'integer', 'exists:teams,id'],
            'channel' => ['sometimes', Rule::in(['email', 'sms', 'whatsapp'])],
            'campaign_type' => ['sometimes', Rule::in([
                Campaign::TYPE_BROADCAST,
                Campaign::TYPE_SCHEDULED,
                Campaign::TYPE_DRIP,
            ])],
            'status' => ['sometimes', Rule::in([
                Campaign::STATUS_DRAFT,
                Campaign::STATUS_SCHEDULED,
                Campaign::STATUS_RUNNING,
                Campaign::STATUS_COMPLETED,
                Campaign::STATUS_PAUSED,
            ])],
            'start_at' => ['sometimes', 'nullable', 'date'],
            'end_at' => ['sometimes', 'nullable', 'date', 'after_or_equal:start_at'],
            'settings' => ['sometimes', 'nullable', 'array'],
            'stop_rules' => ['sometimes', 'nullable', 'array'],
            'stop_rules.opt_out' => ['nullable', 'boolean'],
            'stop_rules.won_lost' => ['nullable', 'boolean'],
            'stop_rules.replied' => ['nullable', 'boolean'],
            'drip_steps' => ['sometimes', 'nullable', 'array', 'max:50'],
            'drip_steps.*.name' => ['nullable', 'string', 'max:255'],
            'drip_steps.*.day' => ['nullable', 'integer', 'min:0', 'max:365'],
            'drip_steps.*.delay_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'drip_steps.*.step_order' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'drip_steps.*.template_id' => ['nullable', 'integer', 'exists:templates,id'],
            'drip_steps.*.channel' => ['nullable', Rule::in(['email', 'sms', 'whatsapp'])],
            'drip_steps.*.is_active' => ['nullable', 'boolean'],
            'drip_steps.*.settings' => ['nullable', 'array'],
        ];

        if (! $isUpdate) {
            $rules['name'][] = 'required';
        }

        return $request->validate($rules);
    }

    /**
     * Validate referenced records belong to active tenant.
     *
     * @param array<string, mixed> $payload
     */
    private function validateTenantReferences(int $tenantId, array $payload): void
    {
        if (array_key_exists('segment_id', $payload) && $payload['segment_id'] !== null) {
            $exists = Segment::query()
                ->withoutTenancy()
                ->where('tenant_id', $tenantId)
                ->whereKey($payload['segment_id'])
                ->exists();

            if (! $exists) {
                abort(422, 'Provided segment_id does not belong to the active tenant.');
            }
        }

        if (array_key_exists('template_id', $payload) && $payload['template_id'] !== null) {
            $exists = Template::query()
                ->withoutTenancy()
                ->where('tenant_id', $tenantId)
                ->whereKey($payload['template_id'])
                ->exists();

            if (! $exists) {
                abort(422, 'Provided template_id does not belong to the active tenant.');
            }
        }

        if (array_key_exists('team_id', $payload) && $payload['team_id'] !== null) {
            $exists = Team::query()
                ->withoutTenancy()
                ->where('tenant_id', $tenantId)
                ->whereKey($payload['team_id'])
                ->exists();

            if (! $exists) {
                abort(422, 'Provided team_id does not belong to the active tenant.');
            }
        }

        foreach (Arr::wrap($payload['drip_steps'] ?? []) as $step) {
            if (! is_array($step) || ! isset($step['template_id']) || $step['template_id'] === null) {
                continue;
            }

            $exists = Template::query()
                ->withoutTenancy()
                ->where('tenant_id', $tenantId)
                ->whereKey((int) $step['template_id'])
                ->exists();

            if (! $exists) {
                abort(422, 'One or more drip step template_id values do not belong to the active tenant.');
            }
        }
    }

    /**
     * Validate campaign launch readiness.
     */
    private function ensureCampaignReadyForLaunch(Campaign $campaign): void
    {
        if ($campaign->segment_id === null) {
            abort(422, 'Campaign must have a segment before launch.');
        }

        if ($campaign->isScheduled() && $campaign->start_at === null) {
            abort(422, 'Scheduled campaigns require start_at.');
        }

        if ($campaign->isDrip()) {
            $activeStepCount = CampaignStep::query()
                ->withoutTenancy()
                ->where('tenant_id', $campaign->tenant_id)
                ->where('campaign_id', $campaign->id)
                ->where('is_active', true)
                ->count();

            if ($activeStepCount === 0) {
                abort(422, 'Drip campaign requires at least one active step.');
            }

            return;
        }

        if ($campaign->template_id === null) {
            abort(422, 'Campaign must have a template before launch.');
        }
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
     * Merge settings and stop_rules payload.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed>|null $existing
     * @return array<string, mixed>
     */
    private function buildSettings(array $payload, ?array $existing): array
    {
        $settings = is_array($existing) ? $existing : [];
        $settings = array_merge($settings, is_array($payload['settings'] ?? null) ? $payload['settings'] : []);

        if (array_key_exists('stop_rules', $payload) && is_array($payload['stop_rules'])) {
            $settings['stop_rules'] = array_merge(
                ['opt_out' => true, 'won_lost' => true, 'replied' => true],
                $payload['stop_rules']
            );
        }

        return $settings;
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

    /**
     * Load campaign with required relations.
     */
    private function loadCampaign(Campaign $campaign): Campaign
    {
        return $campaign->load([
            'segment:id,name,slug',
            'template:id,name,slug,channel',
            'team:id,name',
            'creator:id,name,email',
            'steps' => fn ($query) => $query->withoutTenancy()->orderBy('step_order'),
        ]);
    }
}
