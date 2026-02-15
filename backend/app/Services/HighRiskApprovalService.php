<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\HighRiskApproval;
use App\Models\HighRiskApprovalReview;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class HighRiskApprovalService
{
    /**
     * Decide if one action requires approval for given context.
     *
     * @param array<string, mixed> $context
     */
    public function needsApproval(string $action, array $context = []): bool
    {
        $config = $this->actionConfig($action);

        if (! ($config['enabled'] ?? false)) {
            return false;
        }

        if (isset($config['audience_threshold'])) {
            $audienceCount = (int) ($context['audience_count'] ?? 0);

            return $audienceCount >= (int) $config['audience_threshold'];
        }

        if (isset($config['row_threshold'])) {
            $rowCount = (int) ($context['row_count'] ?? 0);

            return $rowCount >= (int) $config['row_threshold'];
        }

        return true;
    }

    /**
     * Authorize one high-risk action or create/reuse approval request.
     *
     * @param array<string, mixed> $payload
     * @return array{
     *   execute: bool,
     *   approval: HighRiskApproval|null,
     *   created: bool
     * }
     */
    public function authorizeOrRequest(
        int $tenantId,
        User $actor,
        string $action,
        ?string $subjectType,
        ?int $subjectId,
        array $payload,
        ?int $approvalId,
        ?string $reason
    ): array {
        if (! $this->needsApproval($action, $payload)) {
            return [
                'execute' => true,
                'approval' => null,
                'created' => false,
            ];
        }

        $fingerprint = $this->fingerprint($action, $subjectType, $subjectId, $payload);

        if ($approvalId !== null && $approvalId > 0) {
            $explicit = $this->resolveExplicitApproval(
                tenantId: $tenantId,
                actor: $actor,
                approvalId: $approvalId,
                action: $action,
                subjectType: $subjectType,
                subjectId: $subjectId,
                fingerprint: $fingerprint,
            );

            return [
                'execute' => true,
                'approval' => $explicit,
                'created' => false,
            ];
        }

        $reusableApproved = $this->queryBase(
            tenantId: $tenantId,
            action: $action,
            subjectType: $subjectType,
            subjectId: $subjectId,
            fingerprint: $fingerprint,
        )
            ->where('requested_by', $actor->id)
            ->where('status', HighRiskApproval::STATUS_APPROVED)
            ->whereNull('executed_at')
            ->orderByDesc('id')
            ->first();

        if ($reusableApproved instanceof HighRiskApproval) {
            return [
                'execute' => true,
                'approval' => $reusableApproved,
                'created' => false,
            ];
        }

        $existingPending = $this->queryBase(
            tenantId: $tenantId,
            action: $action,
            subjectType: $subjectType,
            subjectId: $subjectId,
            fingerprint: $fingerprint,
        )
            ->where('requested_by', $actor->id)
            ->where('status', HighRiskApproval::STATUS_PENDING)
            ->where(function (Builder $builder): void {
                $builder
                    ->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->orderByDesc('id')
            ->first();

        if ($existingPending instanceof HighRiskApproval) {
            return [
                'execute' => false,
                'approval' => $existingPending,
                'created' => false,
            ];
        }

        $created = HighRiskApproval::query()
            ->withoutTenancy()
            ->create([
                'tenant_id' => $tenantId,
                'action' => $action,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'payload' => $payload,
                'fingerprint' => $fingerprint,
                'requested_by' => $actor->id,
                'required_approvals' => $this->requiredApprovals($action),
                'approved_count' => 0,
                'status' => HighRiskApproval::STATUS_PENDING,
                'reason' => $this->normalizeNullableString($reason),
                'expires_at' => Carbon::now()->addHours((int) config('high_risk.pending_expiry_hours', 72)),
            ]);

        $this->recordActivity(
            tenantId: $tenantId,
            actorId: $actor->id,
            subjectType: HighRiskApproval::class,
            subjectId: (int) $created->id,
            type: 'high_risk.approval.requested',
            description: "Approval requested for '{$action}'.",
            properties: [
                'action' => $action,
                'required_approvals' => $created->required_approvals,
                'subject_type' => $subjectType,
                'subject_id' => $subjectId,
                'payload' => $payload,
            ],
        );

        return [
            'execute' => false,
            'approval' => $created,
            'created' => true,
        ];
    }

    /**
     * Review one pending approval.
     */
    public function review(
        HighRiskApproval $approval,
        User $reviewer,
        bool $approve,
        ?string $comment = null
    ): HighRiskApproval {
        if ($approval->status !== HighRiskApproval::STATUS_PENDING) {
            abort(422, 'Only pending approvals can be reviewed.');
        }

        if ($approval->requested_by !== null && (int) $approval->requested_by === (int) $reviewer->id) {
            abort(422, 'Maker-checker policy: requester cannot approve own request.');
        }

        if ($approval->expires_at !== null && $approval->expires_at->isPast()) {
            $approval->forceFill([
                'status' => HighRiskApproval::STATUS_REJECTED,
                'rejected_at' => now(),
            ])->save();

            abort(422, 'Approval request expired and cannot be reviewed.');
        }

        $alreadyReviewed = HighRiskApprovalReview::query()
            ->withoutTenancy()
            ->where('high_risk_approval_id', $approval->id)
            ->where('reviewer_id', $reviewer->id)
            ->exists();

        if ($alreadyReviewed) {
            abort(422, 'Reviewer already submitted decision for this request.');
        }

        return DB::transaction(function () use ($approval, $reviewer, $approve, $comment): HighRiskApproval {
            $latest = HighRiskApproval::query()
                ->withoutTenancy()
                ->lockForUpdate()
                ->findOrFail($approval->id);

            $stageNo = max(1, (int) $latest->approved_count + 1);

            HighRiskApprovalReview::query()
                ->withoutTenancy()
                ->create([
                    'tenant_id' => $latest->tenant_id,
                    'high_risk_approval_id' => $latest->id,
                    'stage_no' => $stageNo,
                    'reviewer_id' => $reviewer->id,
                    'status' => $approve
                        ? HighRiskApprovalReview::STATUS_APPROVED
                        : HighRiskApprovalReview::STATUS_REJECTED,
                    'comment' => $this->normalizeNullableString($comment),
                    'reviewed_at' => now(),
                ]);

            $properties = [
                'action' => $latest->action,
                'approve' => $approve,
                'stage_no' => $stageNo,
                'required_approvals' => (int) $latest->required_approvals,
            ];

            if (! $approve) {
                $latest->forceFill([
                    'status' => HighRiskApproval::STATUS_REJECTED,
                    'rejected_at' => now(),
                ])->save();

                $this->recordActivity(
                    tenantId: (int) $latest->tenant_id,
                    actorId: $reviewer->id,
                    subjectType: HighRiskApproval::class,
                    subjectId: (int) $latest->id,
                    type: 'high_risk.approval.rejected',
                    description: "Approval rejected for '{$latest->action}'.",
                    properties: $properties,
                );

                return $latest->refresh();
            }

            $nextCount = (int) $latest->approved_count + 1;
            $isFullyApproved = $nextCount >= (int) $latest->required_approvals;

            $latest->forceFill([
                'approved_count' => $nextCount,
                'status' => $isFullyApproved
                    ? HighRiskApproval::STATUS_APPROVED
                    : HighRiskApproval::STATUS_PENDING,
                'approved_at' => $isFullyApproved ? now() : $latest->approved_at,
            ])->save();

            $this->recordActivity(
                tenantId: (int) $latest->tenant_id,
                actorId: $reviewer->id,
                subjectType: HighRiskApproval::class,
                subjectId: (int) $latest->id,
                type: $isFullyApproved
                    ? 'high_risk.approval.approved'
                    : 'high_risk.approval.stage_approved',
                description: $isFullyApproved
                    ? "Approval fully approved for '{$latest->action}'."
                    : "Approval stage approved for '{$latest->action}'.",
                properties: [
                    ...$properties,
                    'approved_count' => $nextCount,
                ],
            );

            return $latest->refresh();
        });
    }

    /**
     * Mark one approved request as executed after action completes.
     *
     * @param array<string, mixed> $executionMeta
     */
    public function markExecuted(HighRiskApproval $approval, int $executedBy, array $executionMeta = []): HighRiskApproval
    {
        if ($approval->status === HighRiskApproval::STATUS_EXECUTED) {
            return $approval->refresh();
        }

        if ($approval->status !== HighRiskApproval::STATUS_APPROVED) {
            abort(422, 'Approval is not in executable state.');
        }

        $approval->forceFill([
            'status' => HighRiskApproval::STATUS_EXECUTED,
            'executed_by' => $executedBy,
            'executed_at' => now(),
        ])->save();

        $this->recordActivity(
            tenantId: (int) $approval->tenant_id,
            actorId: $executedBy,
            subjectType: HighRiskApproval::class,
            subjectId: (int) $approval->id,
            type: 'high_risk.approval.executed',
            description: "Approved action '{$approval->action}' executed.",
            properties: $executionMeta,
        );

        return $approval->refresh();
    }

    /**
     * Resolve explicit approval id and validate execution requirements.
     */
    private function resolveExplicitApproval(
        int $tenantId,
        User $actor,
        int $approvalId,
        string $action,
        ?string $subjectType,
        ?int $subjectId,
        string $fingerprint
    ): HighRiskApproval {
        $approval = HighRiskApproval::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->whereKey($approvalId)
            ->first();

        if (! $approval instanceof HighRiskApproval) {
            abort(404, 'Approval request not found.');
        }

        if ($approval->action !== $action) {
            abort(422, 'Approval request action mismatch.');
        }

        if ($approval->subject_type !== $subjectType || (int) ($approval->subject_id ?? 0) !== (int) ($subjectId ?? 0)) {
            abort(422, 'Approval request subject mismatch.');
        }

        if ($approval->fingerprint !== $fingerprint) {
            abort(422, 'Approval request payload mismatch.');
        }

        if ($approval->status !== HighRiskApproval::STATUS_APPROVED) {
            abort(422, 'Approval request is not approved yet.');
        }

        if ($approval->executed_at !== null) {
            abort(422, 'Approval request already executed.');
        }

        $isRequester = $approval->requested_by !== null && (int) $approval->requested_by === (int) $actor->id;
        if (! $isRequester && ! $actor->isSuperAdmin() && ! $actor->isTenantAdmin()) {
            abort(403, 'Only requester or tenant admin can execute approved action.');
        }

        return $approval;
    }

    /**
     * Build deterministic fingerprint for action payload.
     *
     * @param array<string, mixed> $payload
     */
    private function fingerprint(string $action, ?string $subjectType, ?int $subjectId, array $payload): string
    {
        $normalizedPayload = $this->normalizeForHash($payload);
        $content = json_encode([
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'payload' => $normalizedPayload,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return hash('sha256', (string) $content);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function normalizeForHash(array $payload): array
    {
        ksort($payload);

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->normalizeArrayRecursive($value);
            }
        }

        return $payload;
    }

    /**
     * @param array<int|string, mixed> $value
     * @return array<int|string, mixed>
     */
    private function normalizeArrayRecursive(array $value): array
    {
        if ($value === []) {
            return $value;
        }

        $isAssoc = array_keys($value) !== range(0, count($value) - 1);

        if ($isAssoc) {
            ksort($value);
        }

        foreach ($value as $k => $item) {
            if (is_array($item)) {
                $value[$k] = $this->normalizeArrayRecursive($item);
            }
        }

        return $value;
    }

    /**
     * Shared query matcher for action+subject+fingerprint.
     */
    private function queryBase(
        int $tenantId,
        string $action,
        ?string $subjectType,
        ?int $subjectId,
        string $fingerprint
    ): Builder {
        $query = HighRiskApproval::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->where('action', $action)
            ->where('fingerprint', $fingerprint);

        if ($subjectType === null) {
            $query->whereNull('subject_type');
        } else {
            $query->where('subject_type', $subjectType);
        }

        if ($subjectId === null) {
            $query->whereNull('subject_id');
        } else {
            $query->where('subject_id', $subjectId);
        }

        return $query;
    }

    /**
     * Resolve one action config.
     *
     * @return array<string, mixed>
     */
    private function actionConfig(string $action): array
    {
        $all = config('high_risk.actions', []);

        if (! is_array($all)) {
            return [];
        }

        $raw = $all[$action] ?? [];

        return is_array($raw) ? $raw : [];
    }

    private function requiredApprovals(string $action): int
    {
        $required = (int) ($this->actionConfig($action)['required_approvals'] ?? 1);

        return max(1, min(5, $required));
    }

    private function normalizeNullableString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    /**
     * Record activity trail for approval lifecycle.
     *
     * @param array<string, mixed> $properties
     */
    private function recordActivity(
        int $tenantId,
        ?int $actorId,
        string $subjectType,
        int $subjectId,
        string $type,
        string $description,
        array $properties = []
    ): void {
        Activity::query()->withoutTenancy()->create([
            'tenant_id' => $tenantId,
            'actor_id' => $actorId,
            'type' => $type,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'description' => $description,
            'properties' => $properties,
        ]);
    }
}
