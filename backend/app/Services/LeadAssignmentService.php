<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\AssignmentRule;
use App\Models\Lead;
use App\Models\TeamUser;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LeadAssignmentService
{
    /**
     * Auto-assign a lead based on active assignment rules.
     */
    public function assignLead(Lead $lead, string $trigger = 'intake'): ?User
    {
        if ($lead->owner_id !== null) {
            return User::query()->withoutTenancy()->whereKey($lead->owner_id)->first();
        }

        $rules = AssignmentRule::query()
            ->withoutTenancy()
            ->where('tenant_id', $lead->tenant_id)
            ->where('is_active', true)
            ->orderBy('priority')
            ->orderBy('id')
            ->get();

        foreach ($rules as $rule) {
            if (! $this->isRuleEnabledForTrigger($rule, $trigger)) {
                continue;
            }

            if (! $this->matchesRule($rule, $lead)) {
                continue;
            }

            $assignee = $this->resolveAssignee($rule, $lead);

            if ($assignee === null) {
                continue;
            }

            $lead->forceFill([
                'owner_id' => $assignee->id,
                'team_id' => $rule->team_id ?? $lead->team_id,
            ])->save();

            Activity::query()->create([
                'tenant_id' => $lead->tenant_id,
                'actor_id' => null,
                'type' => 'lead.assigned.auto',
                'subject_type' => Lead::class,
                'subject_id' => $lead->id,
                'description' => 'Lead auto-assigned by assignment rule.',
                'properties' => [
                    'trigger' => $trigger,
                    'rule_id' => $rule->id,
                    'strategy' => $rule->strategy,
                    'assigned_user_id' => $assignee->id,
                    'assigned_team_id' => $rule->team_id,
                ],
            ]);

            return $assignee;
        }

        return null;
    }

    /**
     * Determine whether the rule is enabled for the current trigger.
     */
    private function isRuleEnabledForTrigger(AssignmentRule $rule, string $trigger): bool
    {
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
        return match ($rule->strategy) {
            AssignmentRule::STRATEGY_ROUND_ROBIN => true,
            AssignmentRule::STRATEGY_CITY => $this->matchesCity($rule, $lead),
            AssignmentRule::STRATEGY_INTEREST_SERVICE => $this->matchesInterestOrService($rule, $lead),
            default => false,
        };
    }

    /**
     * Resolve assignee based on rule strategy.
     */
    private function resolveAssignee(AssignmentRule $rule, Lead $lead): ?User
    {
        return match ($rule->strategy) {
            AssignmentRule::STRATEGY_ROUND_ROBIN => $this->resolveRoundRobinUser($rule, $lead),
            AssignmentRule::STRATEGY_CITY, AssignmentRule::STRATEGY_INTEREST_SERVICE => $this->resolveConditionalUser($rule, $lead),
            default => null,
        };
    }

    /**
     * Resolve assignee for conditional (city/interest/service) strategies.
     */
    private function resolveConditionalUser(AssignmentRule $rule, Lead $lead): ?User
    {
        if ($rule->team_id !== null) {
            $assignee = $this->resolveRoundRobinUser($rule, $lead);

            if ($assignee !== null) {
                return $assignee;
            }
        }

        return $this->resolveFallbackOwner($rule, $lead);
    }

    /**
     * Resolve round-robin user by team.
     */
    private function resolveRoundRobinUser(AssignmentRule $rule, Lead $lead): ?User
    {
        $teamId = $rule->team_id ?? $lead->team_id;

        if ($teamId === null) {
            return $this->resolveFallbackOwner($rule, $lead);
        }

        return DB::transaction(function () use ($rule, $lead, $teamId): ?User {
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
                return $this->resolveFallbackOwner($lockedRule, $lead);
            }

            $nextUserId = $this->nextRoundRobinUserId($memberIds, $lockedRule->last_assigned_user_id);

            $assignee = User::query()
                ->withoutTenancy()
                ->where('tenant_id', $lead->tenant_id)
                ->whereKey($nextUserId)
                ->first();

            if ($assignee === null) {
                return $this->resolveFallbackOwner($lockedRule, $lead);
            }

            $lockedRule->forceFill([
                'last_assigned_user_id' => $assignee->id,
                'last_assigned_at' => now(),
            ])->save();

            return $assignee;
        }, 3);
    }

    /**
     * Resolve fallback owner for the rule.
     */
    private function resolveFallbackOwner(AssignmentRule $rule, Lead $lead): ?User
    {
        if ($rule->fallback_owner_id === null) {
            return null;
        }

        return User::query()
            ->withoutTenancy()
            ->whereKey($rule->fallback_owner_id)
            ->where('tenant_id', $lead->tenant_id)
            ->first();
    }

    /**
     * Get next user id for round-robin rotation.
     *
     * @param Collection<int, int> $memberIds
     */
    private function nextRoundRobinUserId(Collection $memberIds, ?int $lastAssignedUserId): int
    {
        if ($lastAssignedUserId === null) {
            return (int) $memberIds->first();
        }

        $index = $memberIds->search($lastAssignedUserId);

        if ($index === false) {
            return (int) $memberIds->first();
        }

        $nextIndex = ($index + 1) % $memberIds->count();

        return (int) $memberIds->get($nextIndex);
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
