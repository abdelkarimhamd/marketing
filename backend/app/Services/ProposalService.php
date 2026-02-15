<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Attachment;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\ProposalTemplate;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProposalService
{
    public function __construct(
        private readonly VariableRenderingService $renderingService,
        private readonly ProposalPdfGeneratorService $pdfGeneratorService,
        private readonly RealtimeEventService $eventService,
    ) {
    }

    /**
     * Generate a new versioned proposal from one template.
     *
     * @param array<string, mixed> $payload
     */
    public function generateFromTemplate(
        Lead $lead,
        ProposalTemplate $template,
        array $payload = [],
        ?User $actor = null,
    ): Proposal {
        if ((int) $lead->tenant_id !== (int) $template->tenant_id) {
            abort(422, 'Lead and proposal template tenant mismatch.');
        }

        $tenantId = (int) $lead->tenant_id;

        return DB::transaction(function () use ($lead, $template, $payload, $actor, $tenantId): Proposal {
            $leadRow = Lead::query()
                ->withoutTenancy()
                ->where('tenant_id', $tenantId)
                ->whereKey((int) $lead->id)
                ->lockForUpdate()
                ->first();

            if (! $leadRow instanceof Lead) {
                abort(404, 'Lead not found for proposal generation.');
            }

            $nextVersion = (int) Proposal::query()
                ->withoutTenancy()
                ->where('tenant_id', $tenantId)
                ->where('lead_id', (int) $leadRow->id)
                ->where('proposal_template_id', (int) $template->id)
                ->max('version_no') + 1;

            $service = $this->stringOrNull($payload['service'] ?? null)
                ?? $this->stringOrNull($template->service)
                ?? $this->stringOrNull($leadRow->service)
                ?? $this->stringOrNull($leadRow->interest);

            $currency = $this->stringOrNull($payload['currency'] ?? null)
                ?? $this->stringOrNull($template->currency)
                ?? $this->stringOrNull(data_get($leadRow->meta, 'proposal.currency'))
                ?? 'USD';

            $quoteAmount = is_numeric($payload['quote_amount'] ?? null)
                ? round((float) $payload['quote_amount'], 2)
                : null;

            $title = $this->stringOrNull($payload['title'] ?? null)
                ?? $this->stringOrNull($template->name)
                ?? 'Proposal';

            $variables = $this->variablesForProposal(
                lead: $leadRow,
                template: $template,
                versionNo: $nextVersion,
                service: $service,
                currency: $currency,
                quoteAmount: $quoteAmount,
                title: $title,
                customVariables: is_array($payload['variables'] ?? null) ? $payload['variables'] : [],
            );

            $subjectTemplate = $this->stringOrNull($payload['subject'] ?? null)
                ?? $this->stringOrNull($template->subject)
                ?? 'Proposal for {{full_name}}';
            $bodyHtmlTemplate = $this->stringOrNull($payload['body_html'] ?? null)
                ?? $this->stringOrNull($template->body_html)
                ?? '<p>Proposal</p>';
            $bodyTextTemplate = $this->stringOrNull($payload['body_text'] ?? null)
                ?? $this->stringOrNull($template->body_text)
                ?? strip_tags((string) $bodyHtmlTemplate);

            $renderedSubject = $this->renderingService->renderString($subjectTemplate, $variables);
            $renderedBodyHtml = $this->renderingService->renderString($bodyHtmlTemplate, $variables);
            $renderedBodyText = $this->renderingService->renderString((string) $bodyTextTemplate, $variables);

            if (trim($renderedBodyText) === '') {
                $renderedBodyText = trim(strip_tags($renderedBodyHtml));
            }

            $pdfBinary = $this->pdfGeneratorService->generate(
                title: $title,
                bodyText: $renderedBodyText,
            );

            $shareToken = $this->generateShareToken($tenantId);

            $proposal = Proposal::query()
                ->withoutTenancy()
                ->create([
                    'tenant_id' => $tenantId,
                    'brand_id' => $leadRow->brand_id ?? $template->brand_id,
                    'lead_id' => (int) $leadRow->id,
                    'proposal_template_id' => (int) $template->id,
                    'created_by' => $actor?->id,
                    'version_no' => $nextVersion,
                    'status' => 'draft',
                    'service' => $service,
                    'currency' => $currency,
                    'quote_amount' => $quoteAmount,
                    'title' => $title,
                    'subject' => $renderedSubject,
                    'body_html' => $renderedBodyHtml,
                    'body_text' => $renderedBodyText,
                    'share_token' => $shareToken,
                    'meta' => [
                        'variables' => $variables,
                        'generated_at' => now()->toIso8601String(),
                    ],
                ]);

            $attachment = $this->storePdfAttachment($leadRow, $proposal, $pdfBinary, $actor?->id);

            $publicUrl = route('public.proposals.view', ['token' => $proposal->share_token], true);

            $proposal->forceFill([
                'pdf_attachment_id' => (int) $attachment->id,
                'public_url' => $publicUrl,
            ])->save();

            Activity::query()->withoutTenancy()->create([
                'tenant_id' => $tenantId,
                'actor_id' => $actor?->id,
                'type' => 'proposal.generated',
                'subject_type' => Lead::class,
                'subject_id' => (int) $leadRow->id,
                'description' => 'Proposal generated for lead.',
                'properties' => [
                    'proposal_id' => (int) $proposal->id,
                    'proposal_template_id' => (int) $template->id,
                    'version_no' => $nextVersion,
                    'service' => $service,
                    'currency' => $currency,
                    'quote_amount' => $quoteAmount,
                    'pdf_attachment_id' => (int) $attachment->id,
                ],
            ]);

            $this->eventService->emit(
                eventName: 'proposal.generated',
                tenantId: $tenantId,
                subjectType: Proposal::class,
                subjectId: (int) $proposal->id,
                payload: [
                    'lead_id' => (int) $leadRow->id,
                    'template_id' => (int) $template->id,
                    'version_no' => $nextVersion,
                    'service' => $service,
                    'currency' => $currency,
                    'quote_amount' => $quoteAmount,
                ],
            );

            return $proposal->refresh()->load([
                'lead:id,tenant_id,first_name,last_name,email,phone,status,brand_id,owner_id,team_id',
                'template:id,tenant_id,name,slug,service,currency',
                'pdfAttachment:id,tenant_id,entity_type,entity_id,storage_disk,storage_path,original_name,mime_type,size_bytes',
            ]);
        });
    }

    /**
     * Mark proposal as opened (first open wins).
     */
    public function markOpened(Proposal $proposal, array $context = []): Proposal
    {
        $alreadyOpened = $proposal->opened_at instanceof Carbon;

        if ($alreadyOpened) {
            return $proposal;
        }

        $newStatus = $proposal->status === 'accepted' ? 'accepted' : 'opened';

        $proposal->forceFill([
            'status' => $newStatus,
            'opened_at' => now(),
            'meta' => array_replace_recursive(
                is_array($proposal->meta) ? $proposal->meta : [],
                ['opened' => $context]
            ),
        ])->save();

        Activity::query()->withoutTenancy()->create([
            'tenant_id' => (int) $proposal->tenant_id,
            'actor_id' => null,
            'type' => 'proposal.opened',
            'subject_type' => Proposal::class,
            'subject_id' => (int) $proposal->id,
            'description' => 'Proposal was opened by recipient.',
            'properties' => [
                'lead_id' => (int) $proposal->lead_id,
                'context' => $context,
            ],
        ]);

        $this->eventService->emit(
            eventName: 'proposal.opened',
            tenantId: (int) $proposal->tenant_id,
            subjectType: Proposal::class,
            subjectId: (int) $proposal->id,
            payload: [
                'lead_id' => (int) $proposal->lead_id,
            ],
        );

        return $proposal->refresh();
    }

    /**
     * Mark proposal as accepted.
     */
    public function markAccepted(Proposal $proposal, ?string $acceptedBy = null, array $context = []): Proposal
    {
        if ($proposal->accepted_at instanceof Carbon) {
            return $proposal;
        }

        $proposal->forceFill([
            'status' => 'accepted',
            'accepted_at' => now(),
            'accepted_by' => $this->stringOrNull($acceptedBy),
            'meta' => array_replace_recursive(
                is_array($proposal->meta) ? $proposal->meta : [],
                ['accepted' => $context]
            ),
        ])->save();

        Activity::query()->withoutTenancy()->create([
            'tenant_id' => (int) $proposal->tenant_id,
            'actor_id' => null,
            'type' => 'proposal.accepted',
            'subject_type' => Proposal::class,
            'subject_id' => (int) $proposal->id,
            'description' => 'Proposal was accepted by recipient.',
            'properties' => [
                'lead_id' => (int) $proposal->lead_id,
                'accepted_by' => $proposal->accepted_by,
                'context' => $context,
            ],
        ]);

        $this->eventService->emit(
            eventName: 'proposal.accepted',
            tenantId: (int) $proposal->tenant_id,
            subjectType: Proposal::class,
            subjectId: (int) $proposal->id,
            payload: [
                'lead_id' => (int) $proposal->lead_id,
                'accepted_by' => $proposal->accepted_by,
            ],
        );

        return $proposal->refresh();
    }

    /**
     * Resolve one proposal by public share token.
     */
    public function findByShareToken(string $token): ?Proposal
    {
        $token = trim($token);

        if ($token === '') {
            return null;
        }

        return Proposal::query()
            ->withoutTenancy()
            ->with([
                'lead:id,tenant_id,first_name,last_name,email,phone,company,status',
                'template:id,tenant_id,name,slug,service,currency',
                'pdfAttachment:id,tenant_id,storage_disk,storage_path,original_name,mime_type,size_bytes',
            ])
            ->where('share_token', $token)
            ->first();
    }

    /**
     * @param array<string, mixed> $customVariables
     * @return array<string, mixed>
     */
    private function variablesForProposal(
        Lead $lead,
        ProposalTemplate $template,
        int $versionNo,
        ?string $service,
        string $currency,
        ?float $quoteAmount,
        string $title,
        array $customVariables,
    ): array {
        $base = $this->renderingService->variablesFromLead($lead);
        $deals = data_get($lead->meta, 'routing.deals');
        $latestDeal = is_array($deals) && $deals !== [] ? end($deals) : null;

        if ($latestDeal !== false && is_array($latestDeal)) {
            $dealVars = [
                'pipeline' => $latestDeal['pipeline'] ?? null,
                'stage' => $latestDeal['stage'] ?? null,
                'title' => $latestDeal['title'] ?? null,
                'status' => $latestDeal['status'] ?? null,
            ];
        } else {
            $dealVars = [
                'pipeline' => null,
                'stage' => (string) $lead->status,
                'title' => null,
                'status' => (string) $lead->status,
            ];
        }

        return array_replace_recursive($base, [
            'service' => $service,
            'deal' => $dealVars,
            'proposal' => [
                'title' => $title,
                'service' => $service,
                'currency' => $currency,
                'quote_amount' => $quoteAmount,
                'version_no' => $versionNo,
                'template' => [
                    'id' => (int) $template->id,
                    'name' => $template->name,
                    'slug' => $template->slug,
                ],
            ],
            'now' => now()->toIso8601String(),
        ], $customVariables);
    }

    private function generateShareToken(int $tenantId): string
    {
        do {
            $token = Str::random(80);
            $exists = Proposal::query()
                ->withoutTenancy()
                ->where('tenant_id', $tenantId)
                ->where('share_token', $token)
                ->exists();
        } while ($exists);

        return $token;
    }

    private function storePdfAttachment(Lead $lead, Proposal $proposal, string $binary, ?int $uploadedBy): Attachment
    {
        $disk = $this->resolveStorageDisk((int) $lead->tenant_id);
        $directory = sprintf(
            'proposals/tenants/%d/leads/%d/%s',
            (int) $lead->tenant_id,
            (int) $lead->id,
            now()->format('Y/m'),
        );
        $filename = sprintf('proposal-%d-v%d.pdf', (int) $proposal->id, (int) $proposal->version_no);
        $path = $directory.'/'.$filename;

        Storage::disk($disk)->put($path, $binary, ['visibility' => 'private']);

        return Attachment::query()->withoutTenancy()->create([
            'tenant_id' => (int) $lead->tenant_id,
            'lead_id' => (int) $lead->id,
            'entity_type' => 'lead',
            'entity_id' => (int) $lead->id,
            'kind' => 'proposal',
            'source' => 'proposal_generator',
            'title' => $proposal->title ?: 'Proposal',
            'description' => 'Generated proposal PDF.',
            'storage_disk' => $disk,
            'storage_path' => $path,
            'original_name' => $filename,
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'size_bytes' => strlen($binary),
            'checksum_sha256' => hash('sha256', $binary),
            'visibility' => 'private',
            'scan_status' => 'not_applicable',
            'scanned_at' => null,
            'scan_engine' => 'internal',
            'scan_result' => 'generated-internally',
            'uploaded_by' => $uploadedBy,
            'meta' => [
                'proposal_id' => (int) $proposal->id,
                'version_no' => (int) $proposal->version_no,
            ],
            'expires_at' => $this->resolveAttachmentExpiration((int) $lead->tenant_id),
        ]);
    }

    private function resolveStorageDisk(int $tenantId): string
    {
        $settings = DB::table('tenants')
            ->where('id', $tenantId)
            ->value('settings');

        if (is_string($settings)) {
            $decoded = json_decode($settings, true);
        } elseif (is_array($settings)) {
            $decoded = $settings;
        } else {
            $decoded = [];
        }

        $tenantDisk = is_array($decoded) ? data_get($decoded, 'attachments.disk') : null;
        $configuredDisk = is_string($tenantDisk) ? trim($tenantDisk) : '';

        if ($configuredDisk !== '' && config('filesystems.disks.'.$configuredDisk) !== null) {
            return $configuredDisk;
        }

        $defaultDisk = trim((string) config('attachments.disk', 'local'));

        if ($defaultDisk !== '' && config('filesystems.disks.'.$defaultDisk) !== null) {
            return $defaultDisk;
        }

        return (string) config('filesystems.default', 'local');
    }

    private function resolveAttachmentExpiration(int $tenantId): ?Carbon
    {
        $settings = DB::table('tenants')
            ->where('id', $tenantId)
            ->value('settings');

        if (is_string($settings)) {
            $decoded = json_decode($settings, true);
        } elseif (is_array($settings)) {
            $decoded = $settings;
        } else {
            $decoded = [];
        }

        $tenantDays = is_array($decoded) ? data_get($decoded, 'retention.attachments_days') : null;
        $days = is_numeric($tenantDays)
            ? (int) $tenantDays
            : (int) config('attachments.retention_days', 365);

        if ($days <= 0) {
            return null;
        }

        return now()->addDays($days);
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
