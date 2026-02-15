<?php

namespace App\Services;

use App\Models\ApprovalRequest;
use App\Models\WorkflowVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class WorkflowVersioningService
{
    /**
     * Create new version snapshot for a workflow subject model.
     */
    public function snapshot(Model $subject, int $tenantId, ?int $createdBy = null, string $status = 'draft'): WorkflowVersion
    {
        $nextVersion = (int) WorkflowVersion::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->where('subject_type', $subject::class)
            ->where('subject_id', $subject->getKey())
            ->max('version_no') + 1;

        return WorkflowVersion::query()
            ->withoutTenancy()
            ->create([
                'tenant_id' => $tenantId,
                'subject_type' => $subject::class,
                'subject_id' => $subject->getKey(),
                'version_no' => $nextVersion,
                'status' => $status,
                'created_by' => $createdBy,
                'payload' => $this->payloadFromSubject($subject),
            ]);
    }

    /**
     * Submit one workflow version for approval.
     */
    public function requestApproval(WorkflowVersion $version, ?int $requestedBy, ?string $comment = null): ApprovalRequest
    {
        return DB::transaction(function () use ($version, $requestedBy, $comment): ApprovalRequest {
            $version->forceFill(['status' => 'pending_approval'])->save();

            return ApprovalRequest::query()
                ->withoutTenancy()
                ->create([
                    'tenant_id' => $version->tenant_id,
                    'workflow_version_id' => $version->id,
                    'requested_by' => $requestedBy,
                    'status' => 'pending',
                    'comment' => $comment,
                ]);
        });
    }

    /**
     * Approve/reject a pending approval request.
     */
    public function review(ApprovalRequest $request, bool $approve, ?int $reviewerId, ?string $comment = null): ApprovalRequest
    {
        return DB::transaction(function () use ($request, $approve, $reviewerId, $comment): ApprovalRequest {
            $request->forceFill([
                'status' => $approve ? 'approved' : 'rejected',
                'reviewed_by' => $reviewerId,
                'reviewed_at' => now(),
                'comment' => $comment ?: $request->comment,
            ])->save();

            $request->workflowVersion->forceFill([
                'status' => $approve ? 'approved' : 'draft',
                'approved_by' => $approve ? $reviewerId : null,
                'approved_at' => $approve ? now() : null,
            ])->save();

            return $request->refresh();
        });
    }

    /**
     * Publish one version by applying payload to subject.
     */
    public function publish(WorkflowVersion $version): WorkflowVersion
    {
        return DB::transaction(function () use ($version): WorkflowVersion {
            $subject = $this->resolveSubject($version);

            if ($subject === null) {
                throw new \RuntimeException('Workflow subject no longer exists.');
            }

            $subject->fill($this->applyablePayload($subject, $version->payload ?? []));
            $subject->save();

            $version->forceFill([
                'status' => 'published',
                'published_at' => now(),
            ])->save();

            return $version->refresh();
        });
    }

    /**
     * Rollback subject to one historical version and save new snapshot.
     */
    public function rollback(WorkflowVersion $targetVersion, ?int $actorId = null): WorkflowVersion
    {
        return DB::transaction(function () use ($targetVersion, $actorId): WorkflowVersion {
            $subject = $this->resolveSubject($targetVersion);

            if ($subject === null) {
                throw new \RuntimeException('Workflow subject no longer exists.');
            }

            $subject->fill($this->applyablePayload($subject, $targetVersion->payload ?? []));
            $subject->save();

            return $this->snapshot(
                subject: $subject->refresh(),
                tenantId: (int) $targetVersion->tenant_id,
                createdBy: $actorId,
                status: 'published'
            );
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadFromSubject(Model $subject): array
    {
        return Arr::except($subject->toArray(), [
            'id',
            'tenant_id',
            'created_at',
            'updated_at',
            'deleted_at',
        ]);
    }

    /**
     * Resolve workflow version subject model.
     */
    private function resolveSubject(WorkflowVersion $version): ?Model
    {
        $class = $version->subject_type;

        if (! is_string($class) || ! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return null;
        }

        /** @var class-string<Model> $class */
        return $class::query()
            ->withoutTenancy()
            ->where('tenant_id', $version->tenant_id)
            ->whereKey($version->subject_id)
            ->first();
    }

    /**
     * Only apply allowed attributes.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function applyablePayload(Model $subject, array $payload): array
    {
        $fillable = $subject->getFillable();

        if ($fillable === []) {
            return $payload;
        }

        return Arr::only($payload, $fillable);
    }
}

