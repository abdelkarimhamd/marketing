<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\DispatchAppWebhookJob;
use App\Models\AppInstall;
use App\Models\AppSecret;
use App\Models\AppWebhook;
use App\Models\AppWebhookDelivery;
use App\Models\MarketplaceApp;
use App\Services\DomainEventBusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MarketplaceController extends Controller
{
    /**
     * List marketplace app catalog + install status.
     */
    public function apps(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'marketplace.view');
        $tenantId = $this->tenantId($request);

        $installedAppIds = AppInstall::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->whereNull('uninstalled_at')
            ->pluck('marketplace_app_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        $apps = MarketplaceApp::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(static function (MarketplaceApp $app) use ($installedAppIds): array {
                return [
                    'id' => (int) $app->id,
                    'name' => $app->name,
                    'slug' => $app->slug,
                    'description' => $app->description,
                    'manifest_url' => $app->manifest_url,
                    'permissions_json' => is_array($app->permissions_json) ? $app->permissions_json : [],
                    'settings' => is_array($app->settings) ? $app->settings : [],
                    'is_installed' => in_array((int) $app->id, $installedAppIds, true),
                ];
            })
            ->values();

        return response()->json([
            'data' => $apps,
        ]);
    }

    /**
     * List installed apps for tenant.
     */
    public function installs(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'marketplace.view');
        $tenantId = $this->tenantId($request);

        $rows = AppInstall::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->with(['app', 'installer:id,name,email', 'webhooks', 'secrets'])
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => $rows,
        ]);
    }

    /**
     * Install one app into tenant.
     */
    public function install(
        Request $request,
        MarketplaceApp $marketplaceApp,
        DomainEventBusService $eventBus
    ): JsonResponse {
        $this->authorizePermission($request, 'marketplace.install');
        $tenantId = $this->tenantId($request);

        $install = AppInstall::query()
            ->withoutTenancy()
            ->updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'marketplace_app_id' => (int) $marketplaceApp->id,
                ],
                [
                    'installed_by' => $request->user()?->id,
                    'status' => 'installed',
                    'config_json' => [],
                    'installed_at' => now(),
                    'uninstalled_at' => null,
                ],
            );

        $secret = $this->rotateSecretInternal($tenantId, $install);

        $eventBus->emit(
            tenantId: $tenantId,
            eventName: 'marketplace.app.installed',
            subjectType: AppInstall::class,
            subjectId: (int) $install->id,
            payload: [
                'app_id' => (int) $marketplaceApp->id,
                'app_slug' => $marketplaceApp->slug,
                'installed_by' => $request->user()?->id,
            ],
        );

        return response()->json([
            'message' => 'App installed successfully.',
            'install' => $install->fresh(['app', 'installer:id,name,email', 'webhooks', 'secrets']),
            'new_secret' => $secret['plain'],
            'key_id' => $secret['key_id'],
        ], 201);
    }

    /**
     * Uninstall one tenant app.
     */
    public function uninstall(Request $request, AppInstall $appInstall, DomainEventBusService $eventBus): JsonResponse
    {
        $this->authorizePermission($request, 'marketplace.install');
        $tenantId = $this->tenantId($request);
        $this->ensureTenantInstall($appInstall, $tenantId);

        $appInstall->forceFill([
            'status' => 'uninstalled',
            'uninstalled_at' => now(),
        ])->save();

        AppWebhook::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->where('app_install_id', (int) $appInstall->id)
            ->update(['is_active' => false]);

        AppSecret::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->where('app_install_id', (int) $appInstall->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        $eventBus->emit(
            tenantId: $tenantId,
            eventName: 'marketplace.app.uninstalled',
            subjectType: AppInstall::class,
            subjectId: (int) $appInstall->id,
            payload: [
                'app_id' => (int) $appInstall->marketplace_app_id,
            ],
        );

        return response()->json([
            'message' => 'App uninstalled successfully.',
            'install' => $appInstall->refresh(),
        ]);
    }

    /**
     * Rotate install secret.
     */
    public function rotateSecret(Request $request, AppInstall $appInstall): JsonResponse
    {
        $this->authorizePermission($request, 'marketplace.update');
        $tenantId = $this->tenantId($request);
        $this->ensureTenantInstall($appInstall, $tenantId);

        $secret = $this->rotateSecretInternal($tenantId, $appInstall);

        return response()->json([
            'message' => 'App secret rotated successfully.',
            'key_id' => $secret['key_id'],
            'secret' => $secret['plain'],
        ]);
    }

    /**
     * Add webhook endpoint for one install.
     */
    public function saveWebhook(Request $request, AppInstall $appInstall): JsonResponse
    {
        $this->authorizePermission($request, 'marketplace.update');
        $tenantId = $this->tenantId($request);
        $this->ensureTenantInstall($appInstall, $tenantId);

        $payload = $request->validate([
            'endpoint_url' => ['required', 'url', 'max:2000'],
            'events_json' => ['nullable', 'array'],
            'settings' => ['nullable', 'array'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $webhook = AppWebhook::query()
            ->withoutTenancy()
            ->create([
                'tenant_id' => $tenantId,
                'app_install_id' => (int) $appInstall->id,
                'endpoint_url' => trim((string) $payload['endpoint_url']),
                'events_json' => is_array($payload['events_json'] ?? null) ? $payload['events_json'] : ['*'],
                'settings' => is_array($payload['settings'] ?? null) ? $payload['settings'] : [],
                'is_active' => array_key_exists('is_active', $payload) ? (bool) $payload['is_active'] : true,
            ]);

        return response()->json([
            'message' => 'Webhook added successfully.',
            'webhook' => $webhook,
        ], 201);
    }

    /**
     * Delete webhook endpoint.
     */
    public function destroyWebhook(Request $request, AppWebhook $appWebhook): JsonResponse
    {
        $this->authorizePermission($request, 'marketplace.delete');
        $tenantId = $this->tenantId($request);

        if ((int) $appWebhook->tenant_id !== $tenantId) {
            abort(404, 'App webhook not found in tenant scope.');
        }

        $appWebhook->delete();

        return response()->json([
            'message' => 'Webhook deleted successfully.',
        ]);
    }

    /**
     * List app webhook deliveries.
     */
    public function deliveries(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'marketplace.view');
        $tenantId = $this->tenantId($request);

        $payload = $request->validate([
            'status' => ['nullable', 'string', 'max:40'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = AppWebhookDelivery::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->with(['webhook', 'domainEvent']);

        if (is_string($payload['status'] ?? null) && trim((string) $payload['status']) !== '') {
            $query->where('status', trim((string) $payload['status']));
        }

        return response()->json(
            $query->orderByDesc('id')
                ->paginate((int) ($payload['per_page'] ?? 20))
                ->withQueryString()
        );
    }

    /**
     * Retry one webhook delivery.
     */
    public function retryDelivery(Request $request, AppWebhookDelivery $appWebhookDelivery): JsonResponse
    {
        $this->authorizePermission($request, 'marketplace.update');
        $tenantId = $this->tenantId($request);

        if ((int) $appWebhookDelivery->tenant_id !== $tenantId) {
            abort(404, 'Delivery not found in tenant scope.');
        }

        DispatchAppWebhookJob::dispatch(
            eventId: (int) $appWebhookDelivery->domain_event_id,
            webhookId: (int) $appWebhookDelivery->app_webhook_id,
        );

        return response()->json([
            'message' => 'Delivery retry queued.',
        ], 202);
    }

    /**
     * @return array{key_id:string,plain:string}
     */
    private function rotateSecretInternal(int $tenantId, AppInstall $appInstall): array
    {
        AppSecret::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->where('app_install_id', (int) $appInstall->id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);

        $plain = 'mkapp_'.Str::random(48);
        $keyId = 'key_'.Str::lower(Str::random(10));

        AppSecret::query()
            ->withoutTenancy()
            ->create([
                'tenant_id' => $tenantId,
                'app_install_id' => (int) $appInstall->id,
                'key_id' => $keyId,
                'secret_encrypted' => encrypt($plain),
                'rotated_at' => now(),
            ]);

        return [
            'key_id' => $keyId,
            'plain' => $plain,
        ];
    }

    private function ensureTenantInstall(AppInstall $appInstall, int $tenantId): void
    {
        if ((int) $appInstall->tenant_id !== $tenantId) {
            abort(404, 'App install not found in tenant scope.');
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
