<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\Proposal;
use App\Models\ProposalTemplate;
use App\Models\Tenant;
use App\Services\ProposalDeliveryService;
use App\Services\ProposalService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProposalController extends Controller
{
    /**
     * List proposals in active tenant.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'leads.view');

        $tenantId = $this->resolveTenantIdStrict($request);
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:40'],
            'service' => ['nullable', 'string', 'max:120'],
            'lead_id' => ['nullable', 'integer', 'min:1'],
            'template_id' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Proposal::query()
            ->withoutTenancy()
            ->with([
                'lead:id,tenant_id,first_name,last_name,email,phone,status',
                'template:id,tenant_id,name,slug,service,currency',
                'pdfAttachment:id,tenant_id,entity_type,entity_id,storage_disk,storage_path,original_name,mime_type,size_bytes',
            ])
            ->where('tenant_id', $tenantId)
            ->orderByDesc('id');

        if (is_string($filters['search'] ?? null) && trim((string) $filters['search']) !== '') {
            $search = trim((string) $filters['search']);

            $query->where(function (Builder $builder) use ($search): void {
                $builder
                    ->where('title', 'like', "%{$search}%")
                    ->orWhere('subject', 'like', "%{$search}%")
                    ->orWhere('service', 'like', "%{$search}%")
                    ->orWhereHas('lead', function (Builder $leadQuery) use ($search): void {
                        $leadQuery
                            ->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        if (is_string($filters['status'] ?? null) && trim((string) $filters['status']) !== '') {
            $query->where('status', trim((string) $filters['status']));
        }

        if (is_string($filters['service'] ?? null) && trim((string) $filters['service']) !== '') {
            $query->where('service', trim((string) $filters['service']));
        }

        if (is_numeric($filters['lead_id'] ?? null)) {
            $query->where('lead_id', (int) $filters['lead_id']);
        }

        if (is_numeric($filters['template_id'] ?? null)) {
            $query->where('proposal_template_id', (int) $filters['template_id']);
        }

        return response()->json(
            $query->paginate((int) ($filters['per_page'] ?? 15))->withQueryString()
        );
    }

    /**
     * Show one proposal.
     */
    public function show(Request $request, Proposal $proposal): JsonResponse
    {
        $this->authorizePermission($request, 'leads.view');

        $tenantId = $this->resolveTenantIdStrict($request);

        if ((int) $proposal->tenant_id !== $tenantId) {
            abort(404, 'Proposal not found.');
        }

        return response()->json([
            'proposal' => $proposal->load([
                'lead:id,tenant_id,first_name,last_name,email,phone,status,brand_id,owner_id,team_id',
                'template:id,tenant_id,name,slug,service,currency,subject,body_html,body_text',
                'pdfAttachment:id,tenant_id,entity_type,entity_id,storage_disk,storage_path,original_name,mime_type,size_bytes',
            ]),
        ]);
    }

    /**
     * Generate proposal from template and lead.
     */
    public function generate(Request $request, ProposalService $proposalService): JsonResponse
    {
        $this->authorizePermission($request, 'templates.send');

        $tenantId = $this->resolveTenantIdStrict($request);
        $payload = $request->validate([
            'lead_id' => ['required', 'integer', 'exists:leads,id'],
            'proposal_template_id' => ['required', 'integer', 'exists:proposal_templates,id'],
            'service' => ['nullable', 'string', 'max:120'],
            'currency' => ['nullable', 'string', 'max:8'],
            'quote_amount' => ['nullable', 'numeric', 'min:0'],
            'title' => ['nullable', 'string', 'max:255'],
            'subject' => ['nullable', 'string', 'max:255'],
            'body_html' => ['nullable', 'string'],
            'body_text' => ['nullable', 'string'],
            'variables' => ['nullable', 'array'],
        ]);

        $lead = Lead::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->whereKey((int) $payload['lead_id'])
            ->first();

        if (! $lead instanceof Lead) {
            abort(422, 'Provided lead_id was not found for active tenant.');
        }

        $template = ProposalTemplate::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->whereKey((int) $payload['proposal_template_id'])
            ->first();

        if (! $template instanceof ProposalTemplate) {
            abort(422, 'Provided proposal_template_id was not found for active tenant.');
        }

        $proposal = $proposalService->generateFromTemplate(
            lead: $lead,
            template: $template,
            payload: $payload,
            actor: $request->user(),
        );

        return response()->json([
            'message' => 'Proposal generated successfully.',
            'proposal' => $proposal,
        ], 201);
    }

    /**
     * Send proposal by one or multiple channels.
     */
    public function send(
        Request $request,
        Proposal $proposal,
        ProposalDeliveryService $deliveryService,
    ): JsonResponse {
        $this->authorizePermission($request, 'templates.send');

        $tenantId = $this->resolveTenantIdStrict($request);

        if ((int) $proposal->tenant_id !== $tenantId) {
            abort(404, 'Proposal not found.');
        }

        $payload = $request->validate([
            'channels' => ['required', 'array', 'min:1', 'max:2'],
            'channels.*' => ['string', 'in:email,whatsapp'],
        ]);

        $result = $deliveryService->sendProposal(
            proposal: $proposal,
            channels: is_array($payload['channels'] ?? null) ? array_values($payload['channels']) : [],
            actor: $request->user(),
        );

        return response()->json([
            'message' => 'Proposal sent successfully.',
            'proposal' => $result['proposal'],
            'messages' => collect($result['messages'])->values(),
        ]);
    }

    private function resolveTenantIdStrict(Request $request): int
    {
        $tenantId = $this->resolveTenantIdForPermission($request, $request->user());

        if ($tenantId !== null && Tenant::query()->whereKey($tenantId)->exists()) {
            return $tenantId;
        }

        abort(422, 'Tenant context is required.');
    }
}
