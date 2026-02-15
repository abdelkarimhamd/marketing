<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\AssignmentRule;
use App\Models\Lead;
use App\Models\Tag;
use App\Models\TeamUser;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LeadAssignmentService
{
    /**
     * Lead statuses treated as closed for capacity/load calculations.
     *
     * @var list<string>
     */
    private const CLOSED_LEAD_STATUSES = ['won', 'lost', 'closed', 'archived', 'converted'];

    /**
     * Tenant timezone lookup cache.
     *
     * @var array<int, string>
     */
    private array $tenantTimezoneCache = [];

    public function __construct(
        private readonly RealtimeEventService $eventService
    ) {
    }

    /**
     * Route a lead through active rules and return first assignee when assigned.
     */
    public function assignLead(Lead $lead, string $trigger = 'intake'): ?User
    {
        $lead = $lead->refresh()->loadMissing('tags:id,name,slug');

        $rules = AssignmentRule::query()
            ->withoutTenancy()
            ->where('tenant_id', $lead->tenant_id)
            ->where('is_active', true)
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        $firstAssignee = null;

        foreach ($rules as $rule) {
            if (! $this->isRuleEnabledForTrigger($rule, $trigger)) {
                continue;
            }

            if (! $this->matchesRule($rule, $lead)) {
                continue;
            }

            $result = $this->executeRuleActions($rule, $lead, $trigger);

            if ($result['assigned_user'] instanceof User && $firstAssignee === null) {
                $firstAssignee = $result['assigned_user'];
            }

            Activity::query()->create([
                'tenant_id' => $lead->tenant_id,
                'actor_id' => null,
                'type' => 'lead.routing.rule_matched',
                'subject_type' => Lead::class,
                'subject_id' => $lead->id,
                'description' => 'Lead matched routing rule.',
                'properties' => [
                    'trigger' => $trigger,
                    'rule_id' => $rule->id,
                    'strategy' => $rule->strategy,
                    'assigned_user_id' => $result['assigned_user']?->id,
                    'executed_actions' => $result['executed_actions'],
                    'stop_processing' => $result['stop_processing'],
                ],
            ]);

            $this->eventService->emit(
                eventName: 'lead.routing.rule_matched',
                tenantId: (int) $lead->tenant_id,
                subjectType: Lead::class,
                subjectId: (int) $lead->id,
                payload: [
                    'trigger' => $trigger,
                    'rule_id' => (int) $rule->id,
                    'strategy' => (string) $rule->strategy,
                    'executed_actions' => $result['executed_actions'],
                    'assigned_user_id' => $result['assigned_user']?->id,
                ],
            );

            $lead = $lead->refresh()->loadMissing('tags:id,name,slug');

            if ($result['stop_processing']) {
                break;
            }
        }

        return $firstAssignee;
    }

    /**
     * Determine whether the rule is enabled for the current trigger.
     */
    private function isRuleEnabledForTrigger(AssignmentRule $rule, string $trigger): bool
    {
        $settings = is_array($rule->settings) ? $rule->settings : [];
        $configuredTriggers = $this->normalizeStringList($settings['triggers'] ?? null);
        $normalizedTrigger = mb_strtolower(trim($trigger));

        if ($configuredTriggers !== []) {
            return in_array('*', $configuredTriggers, true)
                || in_array($normalizedTrigger, $configuredTriggers, true);
        }

        return match ($trigger) {
            'intake' => $rule->auto_assign_on_intake,
            'import' => $rule->auto_assign_on_import,
            default => true,
        };
    }

    /**
     * Determine if a rule applies to the given lead.
     */
    private function matchesRule(AssignmentRule $rule, Lead $lead): bool
    {
        $strategyMatched = match ($rule->strategy) {
            AssignmentRule::STRATEGY_ROUND_ROBIN => true,
            AssignmentRule::STRATEGY_CITY => $this->matchesCity($rule, $lead),
            AssignmentRule::STRATEGY_INTEREST_SERVICE => $this->matchesInterestOrService($rule, $lead),
            AssignmentRule::STRATEGY_RULES_ENGINE => true,
            default => false,
        };

        if (! $strategyMatched) {
            return false;
        }

        return $this->matchesAdvancedConditions($lead, is_array($rule->conditions) ? $rule->conditions : []);
    }

    /**
     * Execute configured actions for a matched rule.
     *
     * @return array{
     *     assigned_user: ?User,
     *     executed_actions: list<string>,
     *     stop_processing: bool
     * }
     */
    private function executeRuleActions(AssignmentRule $rule, Lead $lead, string $trigger): array
    {
        $actions = $this->resolveRuleActions($rule);

        if ($actions === []) {
            return [
                'assigned_user' => null,
                'executed_actions' => [],
                'stop_processing' => false,
            ];
        }

        $assignedUser = null;
        $executedActions = [];
        $stopProcessing = $this->ruleStopsProcessingByDefault($rule);

        foreach ($actions as $action) {
            $type = mb_strtolower(trim((string) ($action['type'] ?? '')));

            if ($type === '' || (($action['enabled'] ?? true) === false)) {
                continue;
            }

            if ($type === AssignmentRule::ACTION_ASSIGN) {
                $resolved = $this->applyAssignAction($rule, $lead, $action, $trigger);

                if ($resolved instanceof User) {
                    $assignedUser = $assignedUser ?? $resolved;
                }
            }

            if ($type === AssignmentRule::ACTION_CREATE_DEAL) {
                $this->applyCreateDealAction($rule, $lead, $action, $trigger);
            }

            if ($type === AssignmentRule::ACTION_ADD_TAGS) {
                $this->applyAddTagsAction($rule, $lead, $action, $trigger);
            }

            if ($type === AssignmentRule::ACTION_START_AUTOMATION) {
                $this->applyStartAutomationAction($rule, $lead, $action, $trigger);
            }

            if ($type === AssignmentRule::ACTION_NOTIFY_CHANNEL) {
                $this->applyNotifyChannelAction($rule, $lead, $action, $trigger);
            }

            $executedActions[] = $type;

            if (($action['stop_processing'] ?? null) !== null) {
                $stopProcessing = (bool) $action['stop_processing'];
            }
        }

        return [
            'assigned_user' => $assignedUser,
            'executed_actions' => $executedActions,
            'stop_processing' => $stopProcessing,
        ];
    }

    /**
     * Resolve configured actions for one rule.
     *
     * @return list<array<string, mixed>>
     */
    private function resolveRuleActions(AssignmentRule $rule): array
    {
        $settings = is_array($rule->settings) ? $rule->settings : [];
        $actions = $settings['actions'] ?? null;

        if (is_array($actions)) {
            $normalized = collect($actions)
                ->filter(static fn (mixed $action): bool => is_array($action))
                ->map(static function (mixed $action): array {
                    $type = mb_strtolower(trim((string) ($action['type'] ?? '')));

                    return array_merge(
                        ['type' => $type],
                        is_array($action) ? $action : []
                    );
                })
                ->filter(static fn (array $action): bool => $action['type'] !== '')
                ->values()
                ->all();

            if ($normalized !== []) {
                return $normalized;
            }
        }

        if ($rule->strategy === AssignmentRule::STRATEGY_RULES_ENGINE) {
            return [];
        }

        return [[
            'type' => AssignmentRule::ACTION_ASSIGN,
            'mode' => $rule->strategy === AssignmentRule::STRATEGY_ROUND_ROBIN ? 'round_robin' : 'conditional',
            'team_id' => $rule->team_id,
            'fallback_owner_id' => $rule->fallback_owner_id,
            'stop_processing' => true,
        ]];
    }

    /**
     * Whether one rule should stop processing by default.
     */
    private function ruleStopsProcessingByDefault(AssignmentRule $rule): bool
    {
        $settings = is_array($rule->settings) ? $rule->settings : [];

        if (($settings['stop_processing'] ?? null) !== null) {
            return (bool) $settings['stop_processing'];
        }

        return true;
    }

    /**
     * Execute assign action.
     */
    private function applyAssignAction(
        AssignmentRule $rule,
        Lead $lead,
        array $action,
        string $trigger
    ): ?User {
        $forceReassign = (bool) ($action['force_reassign'] ?? false);
        $reassignIfUnavailable = (bool) ($action['reassign_if_unavailable'] ?? true);
        $teamId = is_numeric($action['team_id'] ?? null) ? (int) $action['team_id'] : $rule->team_id;
        $fallbackOwnerId = is_numeric($action['fallback_owner_id'] ?? null)
            ? (int) $action['fallback_owner_id']
            : $rule->fallback_owner_id;
        $mode = mb_strtolower(trim((string) ($action['mode'] ?? '')));

        if ($mode === '') {
            $mode = $rule->strategy === AssignmentRule::STRATEGY_ROUND_ROBIN ? 'round_robin' : 'conditional';
        }

        $currentOwner = $this->resolveOwnerById(
            is_numeric($lead->owner_id) ? (int) $lead->owner_id : null,
            $lead
        );
        $currentOwnerAvailability = $currentOwner instanceof User
            ? $this->evaluateUserAvailability($currentOwner, $lead, $rule, $action)
            : null;

        $assignee = null;

        if (is_numeric($action['owner_id'] ?? null)) {
            $preferredOwner = $this->resolveOwnerById((int) $action['owner_id'], $lead);

            if ($preferredOwner instanceof User) {
                $preferredAvailability = $this->evaluateUserAvailability($preferredOwner, $lead, $rule, $action);

                if ($preferredAvailability['available'] || (bool) ($action['allow_unavailable_owner'] ?? false)) {
                    $assignee = $preferredOwner;
                }
            }

            if ($assignee === null && $teamId !== null && $teamId > 0) {
                $assignee = $this->resolveRoundRobinUser($rule, $lead, $teamId, $fallbackOwnerId, $action);
            }

            if ($assignee === null) {
                $assignee = $this->resolveFallbackOwnerById($fallbackOwnerId, $lead, $rule, $action);
            }
        } elseif ($mode === 'round_robin') {
            $assignee = $this->resolveRoundRobinUser($rule, $lead, $teamId, $fallbackOwnerId, $action);
        } elseif ($mode === 'fallback') {
            $assignee = $this->resolveFallbackOwnerById($fallbackOwnerId, $lead, $rule, $action);
        } else {
            $assignee = $this->resolveConditionalUser($rule, $lead, $teamId, $fallbackOwnerId, $action);
        }

        $ownerId = $lead->owner_id;
        $nextOwnerId = $ownerId;
        $shouldKeepCurrentOwner = $ownerId !== null
            && ! $forceReassign
            && (
                ! $reassignIfUnavailable
                || ($currentOwnerAvailability['available'] ?? true)
            );

        if ($assignee !== null && ! $shouldKeepCurrentOwner) {
            $nextOwnerId = $assignee->id;
        }

        $updates = [];

        if ($teamId !== null && $teamId > 0 && $teamId !== $lead->team_id) {
            $updates['team_id'] = $teamId;
        }

        if ($nextOwnerId !== $lead->owner_id) {
            $updates['owner_id'] = $nextOwnerId;
        }

        if ($updates !== []) {
            $lead->forceFill($updates)->save();
        }

        Activity::query()->create([
            'tenant_id' => $lead->tenant_id,
            'actor_id' => null,
            'type' => 'lead.assigned.auto',
            'subject_type' => Lead::class,
            'subject_id' => $lead->id,
            'description' => 'Lead auto-assigned by routing rule.',
            'properties' => [
                'trigger' => $trigger,
                'rule_id' => $rule->id,
                'strategy' => $rule->strategy,
                'mode' => $mode,
                'current_owner_id' => $ownerId,
                'current_owner_available' => $currentOwnerAvailability['available'] ?? null,
                'current_owner_reasons' => $currentOwnerAvailability['reasons'] ?? [],
                'assigned_user_id' => $assignee?->id,
                'assigned_team_id' => $teamId,
                'force_reassign' => $forceReassign,
                'reassign_if_unavailable' => $reassignIfUnavailable,
            ],
        ]);

        return $assignee;
    }

    /**
     * Execute create_deal action.
     */
    private function applyCreateDealAction(
        AssignmentRule $rule,
        Lead $lead,
        array $action,
        string $trigger
    ): void {
        $status = is_string($action['status'] ?? null) ? trim((string) $action['status']) : 'qualified';
        $pipeline = is_string($action['pipeline'] ?? null) ? trim((string) $action['pipeline']) : null;
        $stage = is_string($action['stage'] ?? null) ? trim((string) $action['stage']) : null;
        $dealTitle = is_string($action['title'] ?? null) ? trim((string) $action['title']) : null;

        $meta = is_array($lead->meta) ? $lead->meta : [];
        $entries = data_get($meta, 'routing.deals', []);

        if (! is_array($entries)) {
            $entries = [];
        }

        $entries[] = [
            'created_at' => now()->toIso8601String(),
            'trigger' => $trigger,
            'rule_id' => $rule->id,
            'pipeline' => $pipeline,
            'stage' => $stage,
            'title' => $dealTitle,
            'status' => $status,
        ];

        data_set($meta, 'routing.deals', $entries);

        $updates = ['meta' => $meta];

        if ($status !== '') {
            $updates['status'] = $status;
        }

        $lead->forceFill($updates)->save();

        Activity::query()->create([
            'tenant_id' => $lead->tenant_id,
            'actor_id' => null,
            'type' => 'deal.created.from_rule',
            'subject_type' => Lead::class,
            'subject_id' => $lead->id,
            'description' => 'Deal created by routing rule action.',
            'properties' => [
                'rule_id' => $rule->id,
                'trigger' => $trigger,
                'pipeline' => $pipeline,
                'stage' => $stage,
                'title' => $dealTitle,
                'status' => $status,
            ],
        ]);

        $this->eventService->emit(
            eventName: 'deal.created',
            tenantId: (int) $lead->tenant_id,
            subjectType: Lead::class,
            subjectId: (int) $lead->id,
            payload: [
                'rule_id' => $rule->id,
                'trigger' => $trigger,
                'pipeline' => $pipeline,
                'stage' => $stage,
                'title' => $dealTitle,
                'status' => $status,
            ],
        );
    }

    /**
     * Execute add_tags action.
     */
    private function applyAddTagsAction(
        AssignmentRule $rule,
        Lead $lead,
        array $action,
        string $trigger
    ): void {
        $tagNames = $this->normalizeStringList($action['tags'] ?? $action['tag_names'] ?? null);
        $tagIds = collect(is_array($action['tag_ids'] ?? null) ? $action['tag_ids'] : [])
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->values();

        foreach ($tagNames as $tagName) {
            $tag = Tag::query()->withoutTenancy()->firstOrCreate(
                [
                    'tenant_id' => $lead->tenant_id,
                    'slug' => Str::slug($tagName),
                ],
                [
                    'name' => Str::of($tagName)->replace('-', ' ')->title()->toString(),
                ]
            );

            $tagIds->push((int) $tag->id);
        }

        $tagIds = $tagIds
            ->unique()
            ->values();

        if ($tagIds->isEmpty()) {
            return;
        }

        $lead->tags()->syncWithoutDetaching($tagIds->mapWithKeys(
            static fn (int $tagId): array => [$tagId => ['tenant_id' => $lead->tenant_id]]
        )->all());

        Activity::query()->create([
            'tenant_id' => $lead->tenant_id,
            'actor_id' => null,
            'type' => 'lead.routing.tags_added',
            'subject_type' => Lead::class,
            'subject_id' => $lead->id,
            'description' => 'Lead tagged by routing rule action.',
            'properties' => [
                'rule_id' => $rule->id,
                'trigger' => $trigger,
                'tag_ids' => $tagIds->all(),
                'tags' => $tagNames,
            ],
        ]);
    }

    /**
     * Execute start_automation action.
     */
    private function applyStartAutomationAction(
        AssignmentRule $rule,
        Lead $lead,
        array $action,
        string $trigger
    ): void {
        $automation = is_string($action['automation'] ?? null)
            ? trim((string) $action['automation'])
            : trim((string) ($action['workflow'] ?? ''));

        if ($automation === '') {
            return;
        }

        $payload = is_array($action['payload'] ?? null) ? $action['payload'] : [];
        $meta = is_array($lead->meta) ? $lead->meta : [];
        $entries = data_get($meta, 'routing.automations', []);

        if (! is_array($entries)) {
            $entries = [];
        }

        $entries[] = [
            'automation' => $automation,
            'trigger' => $trigger,
            'rule_id' => $rule->id,
            'started_at' => now()->toIso8601String(),
            'payload' => $payload,
        ];

        data_set($meta, 'routing.automations', $entries);
        $lead->forceFill(['meta' => $meta])->save();

        Activity::query()->create([
            'tenant_id' => $lead->tenant_id,
            'actor_id' => null,
            'type' => 'lead.routing.automation_started',
            'subject_type' => Lead::class,
            'subject_id' => $lead->id,
            'description' => 'Automation start requested by routing rule action.',
            'properties' => [
                'rule_id' => $rule->id,
                'trigger' => $trigger,
                'automation' => $automation,
                'payload' => $payload,
            ],
        ]);

        $this->eventService->emit(
            eventName: 'automation.started',
            tenantId: (int) $lead->tenant_id,
            subjectType: Lead::class,
            subjectId: (int) $lead->id,
            payload: [
                'rule_id' => $rule->id,
                'trigger' => $trigger,
                'automation' => $automation,
                'payload' => $payload,
            ],
        );
    }

    /**
     * Execute notify_channel action.
     */
    private function applyNotifyChannelAction(
        AssignmentRule $rule,
        Lead $lead,
        array $action,
        string $trigger
    ): void {
        $channel = is_string($action['channel'] ?? null) ? trim((string) $action['channel']) : '';

        if ($channel === '') {
            return;
        }

        $template = is_string($action['message'] ?? null)
            ? (string) $action['message']
            : 'Lead {{email}} matched rule {{rule_name}}.';
        $message = $this->interpolateMessage($template, $lead, $rule);
        $payload = is_array($action['payload'] ?? null) ? $action['payload'] : [];

        Activity::query()->create([
            'tenant_id' => $lead->tenant_id,
            'actor_id' => null,
            'type' => 'lead.routing.notified',
            'subject_type' => Lead::class,
            'subject_id' => $lead->id,
            'description' => 'Channel notification requested by routing rule action.',
            'properties' => [
                'rule_id' => $rule->id,
                'trigger' => $trigger,
                'channel' => $channel,
                'message' => $message,
                'payload' => $payload,
            ],
        ]);

        $this->eventService->emit(
            eventName: 'routing.notify.channel',
            tenantId: (int) $lead->tenant_id,
            subjectType: Lead::class,
            subjectId: (int) $lead->id,
            payload: [
                'rule_id' => $rule->id,
                'trigger' => $trigger,
                'channel' => $channel,
                'message' => $message,
                'payload' => $payload,
            ],
        );
    }

    /**
     * Resolve assignee for conditional (city/interest/service) strategies.
     */
    private function resolveConditionalUser(
        AssignmentRule $rule,
        Lead $lead,
        ?int $teamId,
        ?int $fallbackOwnerId,
        array $action = []
    ): ?User
    {
        if ($teamId !== null && $teamId > 0) {
            $assignee = $this->resolveRoundRobinUser($rule, $lead, $teamId, $fallbackOwnerId, $action);

            if ($assignee !== null) {
                return $assignee;
            }
        }

        return $this->resolveFallbackOwnerById($fallbackOwnerId, $lead, $rule, $action);
    }

    /**
     * Resolve round-robin user by team.
     */
    private function resolveRoundRobinUser(
        AssignmentRule $rule,
        Lead $lead,
        ?int $teamId,
        ?int $fallbackOwnerId,
        array $action = []
    ): ?User
    {
        if ($teamId === null || $teamId <= 0) {
            return $this->resolveFallbackOwnerById($fallbackOwnerId, $lead, $rule, $action);
        }

        return DB::transaction(function () use ($rule, $lead, $teamId, $fallbackOwnerId, $action): ?User {
            $lockedRule = AssignmentRule::query()
                ->withoutTenancy()
                ->whereKey($rule->id)
                ->lockForUpdate()
                ->first();

            if ($lockedRule === null) {
                return null;
            }

            $memberIds = TeamUser::query()
                ->withoutTenancy()
                ->where('tenant_id', $lead->tenant_id)
                ->where('team_id', $teamId)
                ->orderBy('id')
                ->pluck('user_id')
                ->unique()
                ->values();

            if ($memberIds->isEmpty()) {
                return $this->resolveFallbackOwnerById($fallbackOwnerId, $lead, $rule, $action);
            }

            $members = User::query()
                ->withoutTenancy()
                ->where('tenant_id', $lead->tenant_id)
                ->whereIn('id', $memberIds->all())
                ->get()
                ->keyBy('id');

            if ($members->isEmpty()) {
                return $this->resolveFallbackOwnerById($fallbackOwnerId, $lead, $rule, $action);
            }

            $orderedUserIds = $this->rotateRoundRobinOrder($memberIds, $lockedRule->last_assigned_user_id);
            $loadsByOwner = $this->activeLeadCountsForOwners((int) $lead->tenant_id, $orderedUserIds->all());

            $selected = null;
            $selectedLoad = null;
            $selectedPriority = null;

            foreach ($orderedUserIds as $priority => $userId) {
                $member = $members->get($userId);

                if (! $member instanceof User) {
                    continue;
                }

                $availability = $this->evaluateUserAvailability(
                    user: $member,
                    lead: $lead,
                    rule: $rule,
                    action: $action,
                    activeLeadCount: $loadsByOwner[(int) $member->id] ?? 0,
                );

                if (! $availability['available']) {
                    continue;
                }

                $load = (int) ($availability['active_lead_count'] ?? 0);

                if (
                    $selected === null
                    || $load < (int) $selectedLoad
                    || ($load === (int) $selectedLoad && (int) $priority < (int) $selectedPriority)
                ) {
                    $selected = $member;
                    $selectedLoad = $load;
                    $selectedPriority = (int) $priority;
                }
            }

            if (! $selected instanceof User) {
                return $this->resolveFallbackOwnerById($fallbackOwnerId, $lead, $rule, $action);
            }

            $lockedRule->forceFill([
                'last_assigned_user_id' => $selected->id,
                'last_assigned_at' => now(),
            ])->save();

            return $selected;
        }, 3);
    }

    /**
     * Resolve fallback owner by ID.
     */
    private function resolveFallbackOwnerById(
        ?int $ownerId,
        Lead $lead,
        ?AssignmentRule $rule = null,
        array $action = []
    ): ?User
    {
        $owner = $this->resolveOwnerById($ownerId, $lead);

        if (! $owner instanceof User) {
            return null;
        }

        if ((bool) ($action['allow_unavailable_fallback'] ?? false)) {
            return $owner;
        }

        $availability = $this->evaluateUserAvailability($owner, $lead, $rule, $action);

        return $availability['available'] ? $owner : null;
    }

    /**
     * Resolve owner by id for active tenant scope.
     *
     * @param int|null $ownerId
     */
    private function resolveOwnerById(?int $ownerId, Lead $lead): ?User
    {
        if ($ownerId === null || $ownerId <= 0) {
            return null;
        }

        return User::query()
            ->withoutTenancy()
            ->whereKey($ownerId)
            ->where('tenant_id', $lead->tenant_id)
            ->first();
    }

    /**
     * Rotate round-robin order from the user after last assignment.
     *
     * @param Collection<int, int> $memberIds
     * @return Collection<int, int>
     */
    private function rotateRoundRobinOrder(Collection $memberIds, ?int $lastAssignedUserId): Collection
    {
        if ($memberIds->isEmpty()) {
            return collect();
        }

        if ($lastAssignedUserId === null) {
            return $memberIds->values();
        }

        $index = $memberIds->search($lastAssignedUserId, strict: true);

        if ($index === false) {
            return $memberIds->values();
        }

        $nextIndex = ((int) $index + 1) % $memberIds->count();

        return $memberIds
            ->slice($nextIndex)
            ->concat($memberIds->slice(0, $nextIndex))
            ->values();
    }

    /**
     * Count active leads by owner for one tenant.
     *
     * @param array<int, int> $ownerIds
     * @return array<int, int>
     */
    private function activeLeadCountsForOwners(int $tenantId, array $ownerIds): array
    {
        $ownerIds = collect($ownerIds)
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($ownerIds === []) {
            return [];
        }

        return Lead::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->whereIn('owner_id', $ownerIds)
            ->whereNotIn('status', self::CLOSED_LEAD_STATUSES)
            ->selectRaw('owner_id, COUNT(*) as total')
            ->groupBy('owner_id')
            ->pluck('total', 'owner_id')
            ->map(static fn (mixed $total): int => (int) $total)
            ->all();
    }

    /**
     * Evaluate if one user is currently available for assignment.
     *
     * @param array<string, mixed> $action
     * @return array{
     *     available: bool,
     *     reasons: list<string>,
     *     active_lead_count: int,
     *     max_active_leads: int|null
     * }
     */
    private function evaluateUserAvailability(
        User $user,
        Lead $lead,
        ?AssignmentRule $rule = null,
        array $action = [],
        ?int $activeLeadCount = null
    ): array {
        $profile = $this->extractUserAvailabilityProfile($user);
        $reasons = [];
        $timezone = is_string($profile['timezone'] ?? null)
            ? trim((string) $profile['timezone'])
            : $this->resolveTenantTimezone((int) $lead->tenant_id);
        $timezone = $timezone !== '' ? $timezone : (string) config('app.timezone', 'UTC');

        try {
            $now = now()->setTimezone($timezone);
        } catch (\Throwable) {
            $now = now();
        }

        $status = mb_strtolower(trim((string) ($profile['status'] ?? 'available')));
        $isOnline = $profile['is_online'] ?? null;

        if (($profile['offline'] ?? false) === true || $isOnline === false || in_array($status, ['offline', 'away', 'unavailable'], true)) {
            $reasons[] = 'offline';
        }

        $offlineAfterMinutes = $action['offline_after_minutes']
            ?? data_get($rule?->settings, 'assignment.offline_after_minutes');

        if (
            is_numeric($offlineAfterMinutes)
            && (int) $offlineAfterMinutes > 0
            && $user->last_seen_at instanceof Carbon
            && $user->last_seen_at->lt(now()->subMinutes((int) $offlineAfterMinutes))
        ) {
            $reasons[] = 'inactive';
        }

        if ($this->isUserOnHoliday($profile, $now)) {
            $reasons[] = 'holiday';
        }

        if (! $this->isWithinUserWorkingSchedule($profile, $now)) {
            $reasons[] = 'outside_working_hours';
        }

        $maxActiveLeads = is_numeric($action['max_active_leads'] ?? null)
            ? (int) $action['max_active_leads']
            : (is_numeric($profile['max_active_leads'] ?? null) ? (int) $profile['max_active_leads'] : 0);

        $activeLeadCount ??= (int) (
            $this->activeLeadCountsForOwners((int) $lead->tenant_id, [(int) $user->id])[(int) $user->id]
            ?? 0
        );

        if ($maxActiveLeads > 0 && $activeLeadCount >= $maxActiveLeads) {
            $reasons[] = 'at_capacity';
        }

        $reasons = array_values(array_unique($reasons));

        return [
            'available' => $reasons === [],
            'reasons' => $reasons,
            'active_lead_count' => $activeLeadCount,
            'max_active_leads' => $maxActiveLeads > 0 ? $maxActiveLeads : null,
        ];
    }

    /**
     * Extract assignment availability profile from user settings.
     *
     * @return array<string, mixed>
     */
    private function extractUserAvailabilityProfile(User $user): array
    {
        $settings = is_array($user->settings) ? $user->settings : [];
        $profile = data_get($settings, 'assignment.availability');

        if (! is_array($profile)) {
            $profile = is_array(data_get($settings, 'availability'))
                ? data_get($settings, 'availability')
                : [];
        }

        return is_array($profile) ? $profile : [];
    }

    /**
     * Determine if user schedule allows assignment at this time.
     *
     * @param array<string, mixed> $profile
     */
    private function isWithinUserWorkingSchedule(array $profile, Carbon $now): bool
    {
        $schedule = is_array($profile['schedule'] ?? null)
            ? $profile['schedule']
            : (is_array($profile['weekly_schedule'] ?? null) ? $profile['weekly_schedule'] : []);

        if ($schedule !== []) {
            $dayKeys = [
                (string) $now->isoWeekday(),
                mb_strtolower($now->englishDayOfWeek),
                mb_strtolower($now->shortEnglishDayOfWeek),
            ];

            $ranges = [];

            foreach ($dayKeys as $key) {
                if (is_array($schedule[$key] ?? null)) {
                    $ranges = array_merge($ranges, $schedule[$key]);
                }
            }

            if ($ranges === [] && is_array($schedule['*'] ?? null)) {
                $ranges = $schedule['*'];
            }

            if ($ranges === []) {
                return false;
            }

            $nowMinutes = ((int) $now->hour * 60) + (int) $now->minute;

            foreach ($ranges as $range) {
                if (! is_array($range)) {
                    continue;
                }

                $start = is_string($range['start'] ?? null) ? trim((string) $range['start']) : null;
                $end = is_string($range['end'] ?? null) ? trim((string) $range['end']) : null;

                if (! $this->isValidClockValue($start) || ! $this->isValidClockValue($end)) {
                    continue;
                }

                if ($this->isWithinClockRange($nowMinutes, $start, $end)) {
                    return true;
                }
            }

            return false;
        }

        $workingHours = is_array($profile['working_hours'] ?? null) ? $profile['working_hours'] : [];

        if ($workingHours === []) {
            return true;
        }

        $days = $this->normalizeWeekdays($workingHours['days'] ?? null);

        if ($days !== [] && ! in_array((int) $now->isoWeekday(), $days, true)) {
            return false;
        }

        $start = is_string($workingHours['start'] ?? null) ? trim((string) $workingHours['start']) : null;
        $end = is_string($workingHours['end'] ?? null) ? trim((string) $workingHours['end']) : null;

        if (! $this->isValidClockValue($start) || ! $this->isValidClockValue($end)) {
            return false;
        }

        $nowMinutes = ((int) $now->hour * 60) + (int) $now->minute;

        return $this->isWithinClockRange($nowMinutes, $start, $end);
    }

    /**
     * Determine if one date is in user holiday schedule.
     *
     * @param array<string, mixed> $profile
     */
    private function isUserOnHoliday(array $profile, Carbon $now): bool
    {
        $holidays = is_array($profile['holidays'] ?? null) ? $profile['holidays'] : [];

        if ($holidays === []) {
            return false;
        }

        $today = $now->copy()->startOfDay();

        foreach ($holidays as $entry) {
            if (is_string($entry)) {
                $day = $this->parseScheduleDate($entry, $now->getTimezone()->getName());

                if ($day !== null && $day->equalTo($today)) {
                    return true;
                }

                continue;
            }

            if (! is_array($entry)) {
                continue;
            }

            $singleDate = $this->parseScheduleDate(
                (string) ($entry['date'] ?? ''),
                $now->getTimezone()->getName()
            );

            if ($singleDate !== null && $singleDate->equalTo($today)) {
                return true;
            }

            $start = $this->parseScheduleDate(
                (string) ($entry['start'] ?? $entry['from'] ?? ''),
                $now->getTimezone()->getName()
            );
            $end = $this->parseScheduleDate(
                (string) ($entry['end'] ?? $entry['to'] ?? ''),
                $now->getTimezone()->getName()
            );

            if ($start !== null && $end === null) {
                $end = $start;
            }

            if ($start !== null && $end !== null) {
                if ($end->lt($start)) {
                    [$start, $end] = [$end, $start];
                }

                if ($today->between($start, $end, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Parse one schedule date value safely.
     */
    private function parseScheduleDate(string $value, string $timezone): ?Carbon
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value, $timezone)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Evaluate minutes against one start/end clock range.
     */
    private function isWithinClockRange(int $nowMinutes, string $start, string $end): bool
    {
        $startMinutes = $this->clockValueToMinutes($start);
        $endMinutes = $this->clockValueToMinutes($end);

        if ($startMinutes === $endMinutes) {
            return true;
        }

        if ($startMinutes < $endMinutes) {
            return $nowMinutes >= $startMinutes && $nowMinutes < $endMinutes;
        }

        return $nowMinutes >= $startMinutes || $nowMinutes < $endMinutes;
    }

    /**
     * Match lead against city conditions.
     */
    private function matchesCity(AssignmentRule $rule, Lead $lead): bool
    {
        $cities = $this->normalizeStringList($rule->conditions['cities'] ?? $rule->conditions['city'] ?? null);

        if ($cities === []) {
            return false;
        }

        $leadCity = mb_strtolower(trim((string) $lead->city));

        if ($leadCity === '') {
            return false;
        }

        return in_array($leadCity, $cities, true);
    }

    /**
     * Match lead against interest/service conditions.
     */
    private function matchesInterestOrService(AssignmentRule $rule, Lead $lead): bool
    {
        $interests = $this->normalizeStringList($rule->conditions['interests'] ?? $rule->conditions['interest'] ?? null);
        $services = $this->normalizeStringList($rule->conditions['services'] ?? $rule->conditions['service'] ?? null);

        if ($interests === [] && $services === []) {
            return false;
        }

        $interestMatch = true;
        $serviceMatch = true;

        if ($interests !== []) {
            $leadInterest = mb_strtolower(trim((string) $lead->interest));
            $interestMatch = $leadInterest !== '' && in_array($leadInterest, $interests, true);
        }

        if ($services !== []) {
            $leadService = mb_strtolower(trim((string) $lead->service));
            $serviceMatch = $leadService !== '' && in_array($leadService, $services, true);
        }

        return $interestMatch && $serviceMatch;
    }

    /**
     * Match advanced rule conditions.
     *
     * Supported keys: source/sources, utm, tags/tag_ids, score, geo, working_hours.
     *
     * @param array<string, mixed> $conditions
     */
    private function matchesAdvancedConditions(Lead $lead, array $conditions): bool
    {
        $checks = [];
        $operator = mb_strtolower(trim((string) ($conditions['operator'] ?? 'all')));

        if (array_key_exists('source', $conditions) || array_key_exists('sources', $conditions)) {
            $checks[] = $this->matchesSourceCondition($lead, $conditions['source'] ?? $conditions['sources']);
        }

        if (
            array_key_exists('utm', $conditions)
            || array_key_exists('utm_source', $conditions)
            || array_key_exists('utm_medium', $conditions)
            || array_key_exists('utm_campaign', $conditions)
            || array_key_exists('utm_content', $conditions)
            || array_key_exists('utm_term', $conditions)
        ) {
            $checks[] = $this->matchesUtmCondition($lead, $conditions);
        }

        if (array_key_exists('tags', $conditions) || array_key_exists('tag_ids', $conditions)) {
            $checks[] = $this->matchesTagCondition($lead, $conditions);
        }

        if (
            array_key_exists('score', $conditions)
            || array_key_exists('min_score', $conditions)
            || array_key_exists('max_score', $conditions)
        ) {
            $checks[] = $this->matchesScoreCondition($lead, $conditions);
        }

        if (
            array_key_exists('geo', $conditions)
            || array_key_exists('countries', $conditions)
            || array_key_exists('country_codes', $conditions)
            || array_key_exists('cities', $conditions)
        ) {
            $checks[] = $this->matchesGeoCondition($lead, $conditions);
        }

        if (array_key_exists('working_hours', $conditions)) {
            $checks[] = $this->matchesWorkingHoursCondition($lead, $conditions['working_hours']);
        }

        if ($checks === []) {
            return true;
        }

        if ($operator === 'any') {
            return in_array(true, $checks, true);
        }

        return ! in_array(false, $checks, true);
    }

    private function matchesSourceCondition(Lead $lead, mixed $sourceCondition): bool
    {
        $allowedSources = $this->normalizeStringList($sourceCondition);

        if ($allowedSources === []) {
            return false;
        }

        $leadSource = mb_strtolower(trim((string) $lead->source));

        return $leadSource !== '' && in_array($leadSource, $allowedSources, true);
    }

    /**
     * @param array<string, mixed> $conditions
     */
    private function matchesUtmCondition(Lead $lead, array $conditions): bool
    {
        $expected = is_array($conditions['utm'] ?? null) ? $conditions['utm'] : [];
        $mapping = [
            'source' => $conditions['utm_source'] ?? null,
            'medium' => $conditions['utm_medium'] ?? null,
            'campaign' => $conditions['utm_campaign'] ?? null,
            'content' => $conditions['utm_content'] ?? null,
            'term' => $conditions['utm_term'] ?? null,
        ];

        foreach ($mapping as $key => $value) {
            if ($value !== null && ! array_key_exists($key, $expected)) {
                $expected[$key] = $value;
            }
        }

        if ($expected === []) {
            return false;
        }

        $leadUtm = $this->extractLeadUtm($lead);

        foreach ($expected as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            $allowedValues = $this->normalizeStringList($value);

            if ($allowedValues === []) {
                return false;
            }

            $actualValue = mb_strtolower(trim((string) ($leadUtm[$key] ?? '')));

            if ($actualValue === '' || ! in_array($actualValue, $allowedValues, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $conditions
     */
    private function matchesTagCondition(Lead $lead, array $conditions): bool
    {
        $lead->loadMissing('tags:id,name,slug');

        $tagIds = collect($lead->tags)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->values()
            ->all();

        $tagNames = collect($lead->tags)
            ->map(static fn (Tag $tag): string => mb_strtolower(trim((string) $tag->name)))
            ->filter()
            ->values()
            ->all();

        $tagSlugs = collect($lead->tags)
            ->map(static fn (Tag $tag): string => mb_strtolower(trim((string) $tag->slug)))
            ->filter()
            ->values()
            ->all();

        $expectedIds = collect(is_array($conditions['tag_ids'] ?? null) ? $conditions['tag_ids'] : [])
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
        $expectedNames = $this->normalizeStringList($conditions['tags'] ?? null);

        if ($expectedIds === [] && $expectedNames === []) {
            return false;
        }

        $idsMatched = $expectedIds === [] || array_intersect($expectedIds, $tagIds) !== [];
        $namesMatched = true;

        if ($expectedNames !== []) {
            $flattened = array_unique(array_merge($tagNames, $tagSlugs));
            $matches = array_intersect($expectedNames, $flattened);
            $requiresAll = mb_strtolower(trim((string) ($conditions['tags_match'] ?? 'any'))) === 'all';

            $namesMatched = $requiresAll
                ? count($matches) === count($expectedNames)
                : $matches !== [];
        }

        return $idsMatched && $namesMatched;
    }

    /**
     * @param array<string, mixed> $conditions
     */
    private function matchesScoreCondition(Lead $lead, array $conditions): bool
    {
        $scoreCondition = is_array($conditions['score'] ?? null) ? $conditions['score'] : [];
        $min = $scoreCondition['min'] ?? $conditions['min_score'] ?? null;
        $max = $scoreCondition['max'] ?? $conditions['max_score'] ?? null;

        if (! is_numeric($min) && ! is_numeric($max)) {
            return false;
        }

        $score = (int) ($lead->score ?? 0);

        if (is_numeric($min) && $score < (int) $min) {
            return false;
        }

        if (is_numeric($max) && $score > (int) $max) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $conditions
     */
    private function matchesGeoCondition(Lead $lead, array $conditions): bool
    {
        $geo = is_array($conditions['geo'] ?? null) ? $conditions['geo'] : [];
        $countries = $this->normalizeStringList(
            $geo['countries'] ?? $geo['country_codes'] ?? $conditions['countries'] ?? $conditions['country_codes'] ?? null
        );
        $cities = $this->normalizeStringList($geo['cities'] ?? $conditions['cities'] ?? null);

        if ($countries === [] && $cities === []) {
            return false;
        }

        if ($countries !== []) {
            $leadCountry = mb_strtolower(trim((string) $lead->country_code));

            if ($leadCountry === '' || ! in_array($leadCountry, $countries, true)) {
                return false;
            }
        }

        if ($cities !== []) {
            $leadCity = mb_strtolower(trim((string) $lead->city));

            if ($leadCity === '' || ! in_array($leadCity, $cities, true)) {
                return false;
            }
        }

        return true;
    }

    private function matchesWorkingHoursCondition(Lead $lead, mixed $workingHours): bool
    {
        if (! is_array($workingHours) || $workingHours === []) {
            return false;
        }

        $timezone = is_string($workingHours['timezone'] ?? null)
            ? trim((string) $workingHours['timezone'])
            : $this->resolveTenantTimezone((int) $lead->tenant_id);
        $timezone = $timezone !== '' ? $timezone : config('app.timezone', 'UTC');

        try {
            $now = now()->setTimezone($timezone);
        } catch (\Throwable) {
            $now = now();
        }

        $days = $this->normalizeWeekdays($workingHours['days'] ?? null);

        if ($days !== [] && ! in_array((int) $now->isoWeekday(), $days, true)) {
            return false;
        }

        $start = is_string($workingHours['start'] ?? null) ? trim((string) $workingHours['start']) : null;
        $end = is_string($workingHours['end'] ?? null) ? trim((string) $workingHours['end']) : null;

        if (! $this->isValidClockValue($start) || ! $this->isValidClockValue($end)) {
            return false;
        }

        $nowMinutes = ((int) $now->hour * 60) + (int) $now->minute;
        $startMinutes = $this->clockValueToMinutes($start);
        $endMinutes = $this->clockValueToMinutes($end);

        if ($startMinutes === $endMinutes) {
            return true;
        }

        if ($startMinutes < $endMinutes) {
            return $nowMinutes >= $startMinutes && $nowMinutes < $endMinutes;
        }

        return $nowMinutes >= $startMinutes || $nowMinutes < $endMinutes;
    }

    /**
     * Extract UTM values from lead metadata.
     *
     * @return array<string, string>
     */
    private function extractLeadUtm(Lead $lead): array
    {
        $meta = is_array($lead->meta) ? $lead->meta : [];
        $utm = is_array($meta['utm'] ?? null) ? $meta['utm'] : [];

        return [
            'source' => mb_strtolower(trim((string) ($utm['source'] ?? $meta['utm_source'] ?? ''))),
            'medium' => mb_strtolower(trim((string) ($utm['medium'] ?? $meta['utm_medium'] ?? ''))),
            'campaign' => mb_strtolower(trim((string) ($utm['campaign'] ?? $meta['utm_campaign'] ?? ''))),
            'content' => mb_strtolower(trim((string) ($utm['content'] ?? $meta['utm_content'] ?? ''))),
            'term' => mb_strtolower(trim((string) ($utm['term'] ?? $meta['utm_term'] ?? ''))),
        ];
    }

    /**
     * Resolve timezone for a tenant with fallback to app timezone.
     */
    private function resolveTenantTimezone(int $tenantId): string
    {
        if (array_key_exists($tenantId, $this->tenantTimezoneCache)) {
            return $this->tenantTimezoneCache[$tenantId];
        }

        $timezone = Tenant::query()
            ->whereKey($tenantId)
            ->value('timezone');

        if (! is_string($timezone) || trim($timezone) === '') {
            $timezone = (string) config('app.timezone', 'UTC');
        }

        $this->tenantTimezoneCache[$tenantId] = $timezone;

        return $timezone;
    }

    /**
     * Normalize weekdays list to ISO day numbers (1..7).
     *
     * @return list<int>
     */
    private function normalizeWeekdays(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $map = [
            'monday' => 1,
            'mon' => 1,
            'tuesday' => 2,
            'tue' => 2,
            'wednesday' => 3,
            'wed' => 3,
            'thursday' => 4,
            'thu' => 4,
            'friday' => 5,
            'fri' => 5,
            'saturday' => 6,
            'sat' => 6,
            'sunday' => 7,
            'sun' => 7,
        ];

        return collect($value)
            ->map(static function (mixed $item) use ($map): ?int {
                if (is_numeric($item)) {
                    $day = (int) $item;

                    return ($day >= 1 && $day <= 7) ? $day : null;
                }

                $key = mb_strtolower(trim((string) $item));

                return $map[$key] ?? null;
            })
            ->filter(static fn (?int $day): bool => $day !== null)
            ->map(static fn (?int $day): int => (int) $day)
            ->unique()
            ->values()
            ->all();
    }

    private function isValidClockValue(?string $value): bool
    {
        return is_string($value) && preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value) === 1;
    }

    private function clockValueToMinutes(string $value): int
    {
        [$hour, $minute] = explode(':', $value, 2);

        return ((int) $hour * 60) + (int) $minute;
    }

    private function interpolateMessage(string $template, Lead $lead, AssignmentRule $rule): string
    {
        $replacements = [
            '{{lead_id}}' => (string) $lead->id,
            '{{email}}' => (string) ($lead->email ?? ''),
            '{{phone}}' => (string) ($lead->phone ?? ''),
            '{{first_name}}' => (string) ($lead->first_name ?? ''),
            '{{last_name}}' => (string) ($lead->last_name ?? ''),
            '{{company}}' => (string) ($lead->company ?? ''),
            '{{city}}' => (string) ($lead->city ?? ''),
            '{{country_code}}' => (string) ($lead->country_code ?? ''),
            '{{source}}' => (string) ($lead->source ?? ''),
            '{{score}}' => (string) ($lead->score ?? 0),
            '{{rule_id}}' => (string) $rule->id,
            '{{rule_name}}' => (string) $rule->name,
        ];

        return trim(strtr($template, $replacements));
    }

    /**
     * Normalize raw string list into lowercase trimmed items.
     *
     * @return list<string>
     */
    private function normalizeStringList(mixed $value): array
    {
        $items = is_array($value) ? $value : [$value];

        return collect($items)
            ->map(static fn (mixed $item): string => mb_strtolower(trim((string) $item)))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
