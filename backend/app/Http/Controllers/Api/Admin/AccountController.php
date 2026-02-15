<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AccountContact;
use App\Models\Activity;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AccountController extends Controller
{
    /**
     * List accounts for active tenant.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'accounts.view');
        $tenantId = $this->tenantId($request);

        $payload = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'owner_user_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Account::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->withCount('contacts')
            ->with(['owner:id,name,email']);

        if (is_string($payload['search'] ?? null) && trim((string) $payload['search']) !== '') {
            $search = trim((string) $payload['search']);
            $query->where(function ($builder) use ($search): void {
                $builder
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('domain', 'like', "%{$search}%")
                    ->orWhere('industry', 'like', "%{$search}%")
                    ->orWhere('city', 'like', "%{$search}%")
                    ->orWhere('country', 'like', "%{$search}%");
            });
        }

        if (is_numeric($payload['owner_user_id'] ?? null) && (int) $payload['owner_user_id'] > 0) {
            $query->where('owner_user_id', (int) $payload['owner_user_id']);
        }

        return response()->json(
            $query->orderBy('name')
                ->paginate((int) ($payload['per_page'] ?? 20))
                ->withQueryString()
        );
    }

    /**
     * Create one account.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'accounts.create');
        $tenantId = $this->tenantId($request);
        $payload = $this->validatePayload($request);

        $ownerId = $this->resolveOwnerId($tenantId, $payload['owner_user_id'] ?? null);

        $account = Account::query()
            ->withoutTenancy()
            ->create([
                'tenant_id' => $tenantId,
                'name' => trim((string) $payload['name']),
                'domain' => $this->nullableString($payload['domain'] ?? null),
                'industry' => $this->nullableString($payload['industry'] ?? null),
                'size' => $this->nullableString($payload['size'] ?? null),
                'city' => $this->nullableString($payload['city'] ?? null),
                'country' => $this->nullableString($payload['country'] ?? null),
                'owner_user_id' => $ownerId,
                'notes' => $this->nullableString($payload['notes'] ?? null),
                'settings' => is_array($payload['settings'] ?? null) ? $payload['settings'] : [],
            ]);

        return response()->json([
            'message' => 'Account created successfully.',
            'account' => $this->mapAccount($account->load(['owner:id,name,email', 'contacts:id,first_name,last_name,email,phone'])),
        ], 201);
    }

    /**
     * Show one account details.
     */
    public function show(Request $request, Account $account): JsonResponse
    {
        $this->authorizePermission($request, 'accounts.view');
        $tenantId = $this->tenantId($request);
        $this->ensureTenantAccount($account, $tenantId);

        return response()->json([
            'account' => $this->mapAccount(
                $account->load([
                    'owner:id,name,email',
                    'contacts:id,first_name,last_name,email,phone,status,owner_id,score',
                ])->loadCount('contacts')
            ),
            'timeline' => $this->timelineRows($tenantId, $account),
            'deals' => $this->dealRows($account),
        ]);
    }

    /**
     * Update one account.
     */
    public function update(Request $request, Account $account): JsonResponse
    {
        $this->authorizePermission($request, 'accounts.update');
        $tenantId = $this->tenantId($request);
        $this->ensureTenantAccount($account, $tenantId);
        $payload = $this->validatePayload($request, true);

        $ownerId = array_key_exists('owner_user_id', $payload)
            ? $this->resolveOwnerId($tenantId, $payload['owner_user_id'])
            : $account->owner_user_id;

        $account->forceFill([
            'name' => array_key_exists('name', $payload) ? trim((string) $payload['name']) : $account->name,
            'domain' => array_key_exists('domain', $payload) ? $this->nullableString($payload['domain']) : $account->domain,
            'industry' => array_key_exists('industry', $payload) ? $this->nullableString($payload['industry']) : $account->industry,
            'size' => array_key_exists('size', $payload) ? $this->nullableString($payload['size']) : $account->size,
            'city' => array_key_exists('city', $payload) ? $this->nullableString($payload['city']) : $account->city,
            'country' => array_key_exists('country', $payload) ? $this->nullableString($payload['country']) : $account->country,
            'owner_user_id' => $ownerId,
            'notes' => array_key_exists('notes', $payload) ? $this->nullableString($payload['notes']) : $account->notes,
            'settings' => array_key_exists('settings', $payload)
                ? (is_array($payload['settings']) ? $payload['settings'] : [])
                : $account->settings,
        ])->save();

        return response()->json([
            'message' => 'Account updated successfully.',
            'account' => $this->mapAccount($account->refresh()->load(['owner:id,name,email'])),
        ]);
    }

    /**
     * Delete one account.
     */
    public function destroy(Request $request, Account $account): JsonResponse
    {
        $this->authorizePermission($request, 'accounts.delete');
        $tenantId = $this->tenantId($request);
        $this->ensureTenantAccount($account, $tenantId);
        $account->delete();

        return response()->json([
            'message' => 'Account deleted successfully.',
        ]);
    }

    /**
     * Attach lead as account contact.
     */
    public function attachContact(Request $request, Account $account): JsonResponse
    {
        $this->authorizePermission($request, 'accounts.link');
        $tenantId = $this->tenantId($request);
        $this->ensureTenantAccount($account, $tenantId);

        $payload = $request->validate([
            'lead_id' => ['required', 'integer'],
            'is_primary' => ['nullable', 'boolean'],
            'job_title' => ['nullable', 'string', 'max:255'],
            'meta' => ['nullable', 'array'],
        ]);

        $lead = Lead::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->whereKey((int) $payload['lead_id'])
            ->first();

        if (! $lead instanceof Lead) {
            abort(404, 'Lead not found in tenant scope.');
        }

        DB::transaction(function () use ($account, $lead, $payload, $tenantId): void {
            if ((bool) ($payload['is_primary'] ?? false)) {
                AccountContact::query()
                    ->withoutTenancy()
                    ->where('tenant_id', $tenantId)
                    ->where('account_id', (int) $account->id)
                    ->update(['is_primary' => false]);
            }

            $account->contacts()->syncWithoutDetaching([
                (int) $lead->id => [
                    'tenant_id' => $tenantId,
                    'is_primary' => (bool) ($payload['is_primary'] ?? false),
                    'job_title' => $this->nullableString($payload['job_title'] ?? null),
                    'meta' => is_array($payload['meta'] ?? null) ? $payload['meta'] : [],
                ],
            ]);
        });

        return response()->json([
            'message' => 'Contact attached to account.',
            'account' => $this->mapAccount($account->refresh()->load(['contacts:id,first_name,last_name,email,phone'])),
        ]);
    }

    /**
     * Detach lead from account.
     */
    public function detachContact(Request $request, Account $account): JsonResponse
    {
        $this->authorizePermission($request, 'accounts.link');
        $tenantId = $this->tenantId($request);
        $this->ensureTenantAccount($account, $tenantId);

        $payload = $request->validate([
            'lead_id' => ['required', 'integer'],
        ]);

        $account->contacts()->detach((int) $payload['lead_id']);

        return response()->json([
            'message' => 'Contact detached from account.',
            'account' => $this->mapAccount($account->refresh()->load(['contacts:id,first_name,last_name,email,phone'])),
        ]);
    }

    /**
     * Timeline for account from linked contacts.
     */
    public function timeline(Request $request, Account $account): JsonResponse
    {
        $this->authorizePermission($request, 'accounts.view');
        $tenantId = $this->tenantId($request);
        $this->ensureTenantAccount($account, $tenantId);

        return response()->json([
            'data' => $this->timelineRows($tenantId, $account),
            'deals' => $this->dealRows($account),
        ]);
    }

    /**
     * Attach lead to account quickly from lead context.
     */
    public function attachLead(Request $request, Lead $lead): JsonResponse
    {
        $this->authorizePermission($request, 'accounts.link');
        $tenantId = $this->tenantId($request);

        if ((int) $lead->tenant_id !== $tenantId) {
            abort(404, 'Lead not found in tenant scope.');
        }

        $payload = $request->validate([
            'account_id' => ['nullable', 'integer'],
            'account_name' => ['nullable', 'string', 'max:255'],
            'is_primary' => ['nullable', 'boolean'],
        ]);

        $account = null;

        if (is_numeric($payload['account_id'] ?? null) && (int) $payload['account_id'] > 0) {
            $account = Account::query()
                ->withoutTenancy()
                ->where('tenant_id', $tenantId)
                ->whereKey((int) $payload['account_id'])
                ->first();
        } elseif (is_string($payload['account_name'] ?? null) && trim((string) $payload['account_name']) !== '') {
            $this->authorizePermission($request, 'accounts.create');

            $account = Account::query()
                ->withoutTenancy()
                ->firstOrCreate(
                    [
                        'tenant_id' => $tenantId,
                        'name' => trim((string) $payload['account_name']),
                    ],
                    [
                        'owner_user_id' => $request->user()?->id,
                    ],
                );
        }

        if (! $account instanceof Account) {
            abort(422, 'account_id or account_name is required.');
        }

        if ((bool) ($payload['is_primary'] ?? true)) {
            AccountContact::query()
                ->withoutTenancy()
                ->where('tenant_id', $tenantId)
                ->where('lead_id', (int) $lead->id)
                ->update(['is_primary' => false]);
        }

        $account->contacts()->syncWithoutDetaching([
            (int) $lead->id => [
                'tenant_id' => $tenantId,
                'is_primary' => (bool) ($payload['is_primary'] ?? true),
            ],
        ]);

        return response()->json([
            'message' => 'Lead attached to account.',
            'account' => $this->mapAccount($account->load(['contacts:id,first_name,last_name,email,phone'])),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function mapAccount(Account $account): array
    {
        return [
            'id' => (int) $account->id,
            'tenant_id' => (int) $account->tenant_id,
            'name' => $account->name,
            'domain' => $account->domain,
            'industry' => $account->industry,
            'size' => $account->size,
            'city' => $account->city,
            'country' => $account->country,
            'notes' => $account->notes,
            'settings' => is_array($account->settings) ? $account->settings : [],
            'owner' => $account->owner?->only(['id', 'name', 'email']),
            'contacts_count' => isset($account->contacts_count) ? (int) $account->contacts_count : null,
            'contacts' => $account->relationLoaded('contacts')
                ? $account->contacts->map(static fn (Lead $lead): array => [
                    'id' => (int) $lead->id,
                    'first_name' => $lead->first_name,
                    'last_name' => $lead->last_name,
                    'email' => $lead->email,
                    'phone' => $lead->phone,
                    'status' => $lead->status,
                    'score' => $lead->score,
                    'pivot' => [
                        'is_primary' => (bool) ($lead->pivot?->is_primary ?? false),
                        'job_title' => $lead->pivot?->job_title,
                        'meta' => is_array($lead->pivot?->meta) ? $lead->pivot->meta : [],
                    ],
                ])->values()->all()
                : [],
            'created_at' => optional($account->created_at)->toIso8601String(),
            'updated_at' => optional($account->updated_at)->toIso8601String(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function timelineRows(int $tenantId, Account $account): array
    {
        $leadIds = $account->contacts()->pluck('leads.id')->map(static fn ($id): int => (int) $id)->all();

        if ($leadIds === []) {
            return [];
        }

        return Activity::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->where('subject_type', Lead::class)
            ->whereIn('subject_id', $leadIds)
            ->orderByDesc('id')
            ->limit(120)
            ->get()
            ->map(static fn (Activity $activity): array => [
                'id' => (int) $activity->id,
                'type' => $activity->type,
                'description' => $activity->description,
                'properties' => is_array($activity->properties) ? $activity->properties : [],
                'created_at' => optional($activity->created_at)->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function dealRows(Account $account): array
    {
        return $account->contacts()
            ->get(['leads.id', 'leads.status', 'leads.score', 'leads.meta', 'leads.updated_at'])
            ->map(static function (Lead $lead): array {
                $deals = data_get($lead->meta, 'routing.deals', []);
                $latestDeal = is_array($deals) && $deals !== [] ? end($deals) : null;

                return [
                    'lead_id' => (int) $lead->id,
                    'status' => $lead->status,
                    'score' => (int) $lead->score,
                    'latest_deal' => is_array($latestDeal) ? $latestDeal : null,
                    'updated_at' => optional($lead->updated_at)->toIso8601String(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function validatePayload(Request $request, bool $isUpdate = false): array
    {
        $required = $isUpdate ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'max:255'],
            'domain' => ['sometimes', 'nullable', 'string', 'max:255'],
            'industry' => ['sometimes', 'nullable', 'string', 'max:120'],
            'size' => ['sometimes', 'nullable', 'string', 'max:120'],
            'city' => ['sometimes', 'nullable', 'string', 'max:120'],
            'country' => ['sometimes', 'nullable', 'string', 'max:120'],
            'owner_user_id' => ['sometimes', 'nullable', 'integer'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'settings' => ['sometimes', 'nullable', 'array'],
        ]);
    }

    private function resolveOwnerId(int $tenantId, mixed $ownerId): ?int
    {
        if ($ownerId === null || $ownerId === '') {
            return null;
        }

        if (! is_numeric($ownerId) || (int) $ownerId <= 0) {
            abort(422, 'owner_user_id must be a valid user id.');
        }

        $exists = User::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->whereKey((int) $ownerId)
            ->exists();

        if (! $exists) {
            abort(422, 'owner_user_id does not belong to this tenant.');
        }

        return (int) $ownerId;
    }

    private function ensureTenantAccount(Account $account, int $tenantId): void
    {
        if ((int) $account->tenant_id !== $tenantId) {
            abort(404, 'Account not found in tenant scope.');
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

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
