<?php

namespace App\Services;

use App\Models\ArchivedMessage;
use App\Models\ArchivedWebhook;
use App\Models\Attachment;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\WebhookInbox;
use Illuminate\Support\Facades\DB;

class RetentionService
{
    public function __construct(
        private readonly AttachmentService $attachmentService
    ) {
    }

    /**
     * Archive old messages/webhooks for one tenant.
     *
     * @return array{archived_messages: int, archived_webhooks: int, deleted_attachments: int}
     */
    public function archiveForTenant(Tenant $tenant, ?int $months = null): array
    {
        $settings = is_array($tenant->settings) ? $tenant->settings : [];
        $retentionMonths = $months
            ?? (int) data_get($settings, 'retention.messages_months', 12);
        $cutoff = now()->subMonths(max(1, $retentionMonths));

        $archivedMessages = 0;
        $archivedWebhooks = 0;
        $deletedAttachments = 0;

        Message::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenant->id)
            ->where('created_at', '<', $cutoff)
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($tenant, &$archivedMessages): void {
                DB::transaction(function () use ($rows, $tenant, &$archivedMessages): void {
                    foreach ($rows as $row) {
                        ArchivedMessage::query()->withoutTenancy()->create([
                            'source_message_id' => $row->id,
                            'tenant_id' => $tenant->id,
                            'payload' => $row->toArray(),
                            'archived_at' => now(),
                        ]);
                        $row->delete();
                        $archivedMessages++;
                    }
                });
            });

        WebhookInbox::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenant->id)
            ->where('created_at', '<', $cutoff)
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($tenant, &$archivedWebhooks): void {
                DB::transaction(function () use ($rows, $tenant, &$archivedWebhooks): void {
                    foreach ($rows as $row) {
                        ArchivedWebhook::query()->withoutTenancy()->create([
                            'source_webhook_id' => $row->id,
                            'tenant_id' => $tenant->id,
                            'provider' => $row->provider,
                            'payload' => $row->toArray(),
                            'archived_at' => now(),
                        ]);
                        $row->delete();
                        $archivedWebhooks++;
                    }
                });
            });

        Attachment::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenant->id)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->chunkById(200, function ($rows) use (&$deletedAttachments): void {
                foreach ($rows as $row) {
                    $this->attachmentService->deleteAttachment($row, force: true);
                    $deletedAttachments++;
                }
            });

        return [
            'archived_messages' => $archivedMessages,
            'archived_webhooks' => $archivedWebhooks,
            'deleted_attachments' => $deletedAttachments,
        ];
    }

    /**
     * Export lead data for right-to-access requests.
     *
     * @return array<string, mixed>|null
     */
    public function exportLeadData(int $tenantId, int $leadId): ?array
    {
        $lead = Lead::query()
            ->withoutTenancy()
            ->with([
                'tags',
                'messages',
                'activities',
                'unsubscribes',
                'consentEvents',
                'preferences',
                'customFieldValues.field',
                'callLogs',
                'attachments',
            ])
            ->where('tenant_id', $tenantId)
            ->whereKey($leadId)
            ->first();

        return $lead?->toArray();
    }

    /**
     * Delete (or anonymize) lead data for right-to-delete requests.
     */
    public function deleteLeadData(int $tenantId, int $leadId, bool $hardDelete = false): bool
    {
        $lead = Lead::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->whereKey($leadId)
            ->first();

        if ($lead === null) {
            return false;
        }

        if ($hardDelete) {
            DB::transaction(function () use ($lead): void {
                foreach ($lead->attachments()->withTrashed()->get() as $attachment) {
                    $this->attachmentService->deleteAttachment($attachment, force: true);
                }

                $lead->messages()->delete();
                $lead->activities()->delete();
                $lead->unsubscribes()->delete();
                $lead->consentEvents()->delete();
                $lead->preferences()->delete();
                $lead->customFieldValues()->delete();
                $lead->callLogs()->delete();
                $lead->forceDelete();
            });

            return true;
        }

        $lead->forceFill([
            'first_name' => null,
            'last_name' => null,
            'email' => null,
            'phone' => null,
            'company' => null,
            'title' => null,
            'meta' => ['anonymized' => true, 'anonymized_at' => now()->toIso8601String()],
        ])->save();

        return true;
    }
}
