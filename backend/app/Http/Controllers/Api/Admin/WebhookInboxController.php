<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\WebhookInbox;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebhookInboxController extends Controller
{
    /**
     * List webhook inbox rows with filters.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizeAdmin($request);

        $payload = $request->validate([
            'provider' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'string', 'max:120'],
            'search' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = WebhookInbox::query();

        if (! empty($payload['provider'])) {
            $query->where('provider', $payload['provider']);
        }

        if (! empty($payload['status'])) {
            $query->where('status', $payload['status']);
        }

        if (! empty($payload['search'])) {
            $search = $payload['search'];

            $query->where(function (Builder $builder) use ($search): void {
                $builder
                    ->where('event', 'like', "%{$search}%")
                    ->orWhere('external_id', 'like', "%{$search}%")
                    ->orWhere('error_message', 'like', "%{$search}%")
                    ->orWhere('payload', 'like', "%{$search}%");
            });
        }

        $rows = $query
            ->orderByDesc('id')
            ->paginate((int) ($payload['per_page'] ?? 25))
            ->withQueryString();

        return response()->json($rows);
    }

    /**
     * Show one webhook inbox row.
     */
    public function show(Request $request, WebhookInbox $webhookInbox): JsonResponse
    {
        $this->authorizeAdmin($request);

        return response()->json([
            'webhook' => $webhookInbox,
        ]);
    }

    /**
     * Ensure caller has admin permission.
     */
    private function authorizeAdmin(Request $request): void
    {
        $user = $request->user();

        if (! $user || ! $user->isAdmin()) {
            abort(403, 'Admin permissions are required.');
        }
    }
}
