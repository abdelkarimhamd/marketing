<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\IntegrationConnection;
use App\Models\IntegrationEventSubscription;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class IntegrationController extends Controller
{
    /**
     * List tenant integrations and event subscriptions.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'settings.view', requireTenantContext: false);
        $tenantId = $this->tenantId($request);

        return response()->json([
            'connections' => IntegrationConnection::query()
                ->withoutTenancy()
                ->where('tenant_id', $tenantId)
                ->orderBy('provider')
                ->orderBy('name')
                ->get(),
            'event_subscriptions' => IntegrationEventSubscription::query()
                ->withoutTenancy()
                ->where('tenant_id', $tenantId)
                ->orderBy('name')
                ->get(),
        ]);
    }

    /**
     * Create/update integration connection.
     */
    public function saveConnection(Request $request, ?IntegrationConnection $integrationConnection = null): JsonResponse
    {
        $this->authorizePermission($request, 'settings.update', requireTenantContext: false);
        $tenantId = $this->tenantId($request);

        if ($integrationConnection !== null && (int) $integrationConnection->tenant_id !== $tenantId) {
            abort(404, 'Integration not found in tenant scope.');
        }

        $payload = $request->validate([
            'provider' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:150'],
            'config' => ['nullable', 'array'],
            'secrets' => ['nullable', 'array'],
            'capabilities' => ['nullable', 'array'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $model = ($integrationConnection ?? new IntegrationConnection())->fill([
            'tenant_id' => $tenantId,
            'provider' => $payload['provider'],
            'name' => $payload['name'],
            'config' => $payload['config'] ?? [],
            'secrets' => $payload['secrets'] ?? [],
            'capabilities' => $payload['capabilities'] ?? [],
            'is_active' => $payload['is_active'] ?? true,
        ]);
        $model->save();

        return response()->json([
            'message' => 'Integration connection saved.',
            'connection' => $model,
        ], $integrationConnection ? 200 : 201);
    }

    /**
     * Delete integration connection.
     */
    public function destroyConnection(Request $request, IntegrationConnection $integrationConnection): JsonResponse
    {
        $this->authorizePermission($request, 'settings.update', requireTenantContext: false);
        $tenantId = $this->tenantId($request);

        if ((int) $integrationConnection->tenant_id !== $tenantId) {
            abort(404, 'Integration not found in tenant scope.');
        }

        $integrationConnection->delete();

        return response()->json([
            'message' => 'Integration connection deleted.',
        ]);
    }

    /**
     * Create/update event subscription for outbound triggers.
     */
    public function saveEventSubscription(
        Request $request,
        ?IntegrationEventSubscription $integrationEventSubscription = null
    ): JsonResponse {
        $this->authorizePermission($request, 'settings.update', requireTenantContext: false);
        $tenantId = $this->tenantId($request);

        if ($integrationEventSubscription !== null && (int) $integrationEventSubscription->tenant_id !== $tenantId) {
            abort(404, 'Event subscription not found in tenant scope.');
        }

        $payload = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'endpoint_url' => ['required', 'url', 'max:2000'],
            'secret' => ['nullable', 'string', 'max:255'],
            'events' => ['nullable', 'array'],
            'events.*' => ['string', 'max:120'],
            'is_active' => ['nullable', 'boolean'],
            'settings' => ['nullable', 'array'],
        ]);

        $model = ($integrationEventSubscription ?? new IntegrationEventSubscription())->fill([
            'tenant_id' => $tenantId,
            'name' => $payload['name'],
            'endpoint_url' => $payload['endpoint_url'],
            'secret' => $payload['secret'] ?? null,
            'events' => $payload['events'] ?? ['*'],
            'is_active' => $payload['is_active'] ?? true,
            'settings' => $payload['settings'] ?? [],
        ]);
        $model->save();

        return response()->json([
            'message' => 'Event subscription saved.',
            'event_subscription' => $model,
        ], $integrationEventSubscription ? 200 : 201);
    }

    /**
     * Delete event subscription.
     */
    public function destroyEventSubscription(
        Request $request,
        IntegrationEventSubscription $integrationEventSubscription
    ): JsonResponse {
        $this->authorizePermission($request, 'settings.update', requireTenantContext: false);
        $tenantId = $this->tenantId($request);

        if ((int) $integrationEventSubscription->tenant_id !== $tenantId) {
            abort(404, 'Event subscription not found in tenant scope.');
        }

        $integrationEventSubscription->delete();

        return response()->json([
            'message' => 'Event subscription deleted.',
        ]);
    }

    private function tenantId(Request $request): int
    {
        $tenantId = $request->attributes->get('tenant_id');

        if (is_int($tenantId) && $tenantId > 0) {
            return $tenantId;
        }

        $requested = $request->query('tenant_id', $request->input('tenant_id'));

        if (is_numeric($requested) && (int) $requested > 0) {
            $tenant = Tenant::query()->whereKey((int) $requested)->first();

            if ($tenant !== null) {
                return $tenant->id;
            }
        }

        abort(422, 'Tenant context is required.');
    }
}

