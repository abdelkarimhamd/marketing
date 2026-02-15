<?php

namespace App\Services;

use App\Messaging\MessageDispatcher;
use App\Models\Activity;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Proposal;
use App\Models\User;

class ProposalDeliveryService
{
    public function __construct(
        private readonly MessageDispatcher $messageDispatcher,
        private readonly MessageStatusService $messageStatusService,
        private readonly RealtimeEventService $eventService,
    ) {
    }

    /**
     * Send one proposal via email and/or WhatsApp.
     *
     * @param list<string> $channels
     * @return array{proposal: Proposal, messages: list<Message>}
     */
    public function sendProposal(Proposal $proposal, array $channels, ?User $actor = null): array
    {
        $proposal = $proposal->loadMissing(['lead.brand', 'lead.owner', 'lead.team', 'brand']);

        if (! $proposal->lead instanceof Lead) {
            abort(422, 'Proposal lead was not found.');
        }

        $normalizedChannels = collect($channels)
            ->map(static fn (mixed $value): string => trim(mb_strtolower((string) $value)))
            ->filter(static fn (string $value): bool => in_array($value, ['email', 'whatsapp'], true))
            ->unique()
            ->values()
            ->all();

        if ($normalizedChannels === []) {
            abort(422, 'At least one channel is required: email or whatsapp.');
        }

        $publicUrl = is_string($proposal->public_url) && trim($proposal->public_url) !== ''
            ? trim($proposal->public_url)
            : route('public.proposals.view', ['token' => $proposal->share_token], true);
        $acceptUrl = route('public.proposals.accept', ['token' => $proposal->share_token], true);
        $pdfUrl = route('public.proposals.pdf', ['token' => $proposal->share_token], true);

        $sentMessages = [];

        foreach ($normalizedChannels as $channel) {
            $message = $this->createMessageForChannel(
                proposal: $proposal,
                channel: $channel,
                actor: $actor,
                publicUrl: $publicUrl,
                acceptUrl: $acceptUrl,
                pdfUrl: $pdfUrl,
            );

            $sentMessages[] = $this->dispatchMessage($message);
        }

        if ($sentMessages === []) {
            abort(422, 'No messages were prepared for selected channels.');
        }

        $proposal->forceFill([
            'status' => 'sent',
            'sent_at' => $proposal->sent_at ?? now(),
            'public_url' => $publicUrl,
            'meta' => array_replace_recursive(
                is_array($proposal->meta) ? $proposal->meta : [],
                [
                    'delivery' => [
                        'channels' => $normalizedChannels,
                        'message_ids' => collect($sentMessages)->map(static fn (Message $message): int => (int) $message->id)->all(),
                        'sent_at' => now()->toIso8601String(),
                    ],
                ],
            ),
        ])->save();

        Activity::query()->withoutTenancy()->create([
            'tenant_id' => (int) $proposal->tenant_id,
            'actor_id' => $actor?->id,
            'type' => 'proposal.sent',
            'subject_type' => Proposal::class,
            'subject_id' => (int) $proposal->id,
            'description' => 'Proposal sent to lead.',
            'properties' => [
                'lead_id' => (int) $proposal->lead_id,
                'channels' => $normalizedChannels,
                'message_ids' => collect($sentMessages)->map(static fn (Message $message): int => (int) $message->id)->all(),
            ],
        ]);

        $this->eventService->emit(
            eventName: 'proposal.sent',
            tenantId: (int) $proposal->tenant_id,
            subjectType: Proposal::class,
            subjectId: (int) $proposal->id,
            payload: [
                'lead_id' => (int) $proposal->lead_id,
                'channels' => $normalizedChannels,
                'message_ids' => collect($sentMessages)->map(static fn (Message $message): int => (int) $message->id)->all(),
            ],
        );

        return [
            'proposal' => $proposal->refresh()->loadMissing(['lead', 'template', 'pdfAttachment']),
            'messages' => $sentMessages,
        ];
    }

    private function createMessageForChannel(
        Proposal $proposal,
        string $channel,
        ?User $actor,
        string $publicUrl,
        string $acceptUrl,
        string $pdfUrl,
    ): Message {
        $lead = $proposal->lead;
        $subject = trim((string) ($proposal->subject ?? 'Your proposal'));

        $plainBody = "Proposal: {$proposal->title}\nView: {$publicUrl}\nPDF: {$pdfUrl}\nAccept: {$acceptUrl}";
        $htmlBody = sprintf(
            '<p>Your proposal is ready.</p><p><strong>%s</strong></p><p><a href="%s">View Proposal</a></p><p><a href="%s">Download PDF</a></p><p><a href="%s">Accept Proposal</a></p>',
            e((string) $proposal->title),
            e($publicUrl),
            e($pdfUrl),
            e($acceptUrl),
        );

        if ($channel === 'email') {
            $to = is_string($lead->email) ? trim($lead->email) : '';

            if ($to === '') {
                abort(422, 'Lead email is required to send proposal via email.');
            }

            return Message::query()->withoutTenancy()->create([
                'tenant_id' => (int) $proposal->tenant_id,
                'brand_id' => $proposal->brand_id,
                'lead_id' => (int) $proposal->lead_id,
                'user_id' => $actor?->id,
                'direction' => 'outbound',
                'status' => 'queued',
                'channel' => 'email',
                'to' => $to,
                'from' => $this->resolveEmailFrom($proposal),
                'subject' => $subject,
                'body' => $htmlBody,
                'provider' => null,
                'meta' => [
                    'proposal' => [
                        'id' => (int) $proposal->id,
                        'version_no' => (int) $proposal->version_no,
                        'public_url' => $publicUrl,
                        'accept_url' => $acceptUrl,
                        'pdf_url' => $pdfUrl,
                    ],
                ],
            ]);
        }

        $to = is_string($lead->phone) ? trim($lead->phone) : '';

        if ($to === '') {
            abort(422, 'Lead phone is required to send proposal via WhatsApp.');
        }

        return Message::query()->withoutTenancy()->create([
            'tenant_id' => (int) $proposal->tenant_id,
            'brand_id' => $proposal->brand_id,
            'lead_id' => (int) $proposal->lead_id,
            'user_id' => $actor?->id,
            'direction' => 'outbound',
            'status' => 'queued',
            'channel' => 'whatsapp',
            'to' => $to,
            'from' => $this->resolveWhatsAppFrom($proposal),
            'subject' => null,
            'body' => $plainBody,
            'provider' => null,
            'meta' => [
                'proposal' => [
                    'id' => (int) $proposal->id,
                    'version_no' => (int) $proposal->version_no,
                    'public_url' => $publicUrl,
                    'accept_url' => $acceptUrl,
                    'pdf_url' => $pdfUrl,
                ],
                'phone_number_id' => $this->resolveWhatsAppFrom($proposal),
            ],
        ]);
    }

    private function dispatchMessage(Message $message): Message
    {
        try {
            $result = $this->messageDispatcher->dispatch($message->refresh());
            $status = $result->accepted ? $result->status : 'failed';

            return $this->messageStatusService->markDispatched(
                message: $message,
                provider: $result->provider,
                providerMessageId: $result->providerMessageId,
                status: $status,
                errorMessage: $result->errorMessage,
                meta: is_array($result->meta) ? $result->meta : [],
            );
        } catch (\Throwable $exception) {
            return $this->messageStatusService->markDispatched(
                message: $message,
                provider: 'system',
                providerMessageId: null,
                status: 'failed',
                errorMessage: $exception->getMessage(),
            );
        }
    }

    private function resolveEmailFrom(Proposal $proposal): ?string
    {
        $brand = $proposal->brand;

        if (is_string($brand?->email_from_address) && trim((string) $brand->email_from_address) !== '') {
            return trim((string) $brand->email_from_address);
        }

        return trim((string) config('mail.from.address', '')) ?: null;
    }

    private function resolveWhatsAppFrom(Proposal $proposal): ?string
    {
        $brand = $proposal->brand;

        if (is_string($brand?->whatsapp_phone_number_id) && trim((string) $brand->whatsapp_phone_number_id) !== '') {
            return trim((string) $brand->whatsapp_phone_number_id);
        }

        $leadSettingsPhoneId = data_get($proposal->lead?->meta, 'whatsapp.phone_number_id');

        if (is_string($leadSettingsPhoneId) && trim($leadSettingsPhoneId) !== '') {
            return trim($leadSettingsPhoneId);
        }

        return trim((string) config('messaging.meta_whatsapp.phone_number_id', '')) ?: null;
    }
}
