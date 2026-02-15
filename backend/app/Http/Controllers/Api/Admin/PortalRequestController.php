<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\PortalRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PortalRequestController extends Controller
{
    /**
     * List portal requests inbox.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'portal_requests.view');
        $tenantId = $this->tenantId($request);

        $payload = $request->validate([
            'status' => ['nullable', 'string', 'max:40'],
            'request_type' => ['nullable', 'string', 'max:40'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = PortalRequest::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->with([
                'lead:id,first_name,last_name,email,phone,status',
                'assignedTo:id,name,email',
                'convertedBy:id,name,email',
            ]);

        if (is_string($payload['status'] ?? null) && trim((string) $payload['status']) !== '') {
            $query->where('status', trim((string) $payload['status']));
        }

        if (is_string($payload['request_type'] ?? null) && trim((string) $payload['request_type']) !== '') {
            $query->where('request_type', trim((string) $payload['request_type']));
        }

        return response()->json(
            $query->orderByDesc('id')
                ->paginate((int) ($payload['per_page'] ?? 20))
                ->withQueryString()
        );
    }

    /**
     * Update request assignment/status.
     */
    public function update(Request $request, PortalRequest $portalRequest): JsonResponse
    {
        $this->authorizePermission($request, 'portal_requests.update');
        $tenantId = $this->tenantId($request);
        $this->ensureTenantPortalRequest($portalRequest, $tenantId);

        $payload = $request->validate([
            'status' => ['sometimes', Rule::in(['new', 'in_progress', 'qualified', 'converted', 'closed'])],
            'assigned_to' => ['sometimes', 'nullable', 'integer'],
            'meta' => ['sometimes', 'array'],
        ]);

        $portalRequest->forceFill([
            'status' => array_key_exists('status', $payload) ? (string) $payload['status'] : $portalRequest->status,
            'assigned_to' => array_key_exists('assigned_to', $payload)
                ? (is_numeric($payload['assigned_to']) ? (int) $payload['assigned_to'] : null)
                : $portalRequest->assigned_to,
            'meta' => array_key_exists('meta', $payload)
                ? (is_array($payload['meta']) ? $payload['meta'] : [])
                : $portalRequest->meta,
        ])->save();

        return response()->json([
            'message' => 'Portal request updated.',
            'portal_request' => $portalRequest->refresh()->load([
                'lead:id,first_name,last_name,email,phone,status',
                'assignedTo:id,name,email',
                'convertedBy:id,name,email',
            ]),
        ]);
    }

    /**
     * Convert request to lead/deal context.
     */
    public function convert(Request $request, PortalRequest $portalRequest): JsonResponse
    {
        $this->authorizePermission($request, 'portal_requests.convert');
        $tenantId = $this->tenantId($request);
        $this->ensureTenantPortalRequest($portalRequest, $tenantId);

        $lead = $portalRequest->lead;
        $payload = is_array($portalRequest->payload_json) ? $portalRequest->payload_json : [];

        if (! $lead instanceof Lead) {
            $lead = Lead::query()
                ->withoutTenancy()
                ->create([
                    'tenant_id' => $tenantId,
                    'first_name' => is_string($payload['first_name'] ?? null) ? trim((string) $payload['first_name']) : null,
                    'last_name' => is_string($payload['last_name'] ?? null) ? trim((string) $payload['last_name']) : null,
                    'email' => is_string($payload['email'] ?? null) ? trim((string) $payload['email']) : null,
                    'phone' => is_string($payload['phone'] ?? null) ? trim((string) $payload['phone']) : null,
                    'company' => is_string($payload['company'] ?? null) ? trim((string) $payload['company']) : null,
                    'city' => is_string($payload['city'] ?? null) ? trim((string) $payload['city']) : null,
                    'country_code' => is_string($payload['country_code'] ?? null) ? trim((string) $payload['country_code']) : null,
                    'status' => 'new',
                    'source' => 'portal',
                    'score' => 0,
                    'meta' => [
                        'portal_request_id' => (int) $portalRequest->id,
                    ],
                ]);
        }

        $portalRequest->forceFill([
            'lead_id' => (int) $lead->id,
            'status' => 'converted',
            'converted_by' => $request->user()?->id,
            'converted_at' => now(),
        ])->save();

        return response()->json([
            'message' => 'Portal request converted.',
            'portal_request' => $portalRequest->refresh()->load([
                'lead:id,first_name,last_name,email,phone,status,score',
                'assignedTo:id,name,email',
                'convertedBy:id,name,email',
            ]),
        ]);
    }

    private function ensureTenantPortalRequest(PortalRequest $portalRequest, int $tenantId): void
    {
        if ((int) $portalRequest->tenant_id !== $tenantId) {
            abort(404, 'Portal request not found in tenant scope.');
        }
    }

    private function tenantId(Request $request): int
    {
        $tenantId = $this->resolveTenantIdForPermission($request, $request->user());

        if ($tenantId === null || $tenantId <= 0) {
            abort(422, 'Tenant context is required.');
        }

        return $tenantId;
    }
}
