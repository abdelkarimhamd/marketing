<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantRoleTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    /**
     * List users for active tenant with availability profile.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'roles.assign');

        $tenantId = $this->resolveTenantIdStrict($request);
        $users = User::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->orderBy('name')
            ->get();

        return response()->json([
            'users' => $users->map(function (User $user): array {
                return [
                    ...$user->only(['id', 'tenant_id', 'name', 'email', 'role', 'last_seen_at']),
                    'availability' => $this->extractAvailabilitySettings($user),
                    'booking_link' => $this->extractBookingLink($user),
                ];
            })->values(),
        ]);
    }

    /**
     * Create one tenant user (super admin or tenant admin scope).
     */
    public function store(Request $request, TenantRoleTemplateService $templateService): JsonResponse
    {
        $this->authorizePermission($request, 'roles.assign');

        $tenantId = $this->resolveTenantIdStrict($request);
        $templateKeys = array_keys($templateService->templates());

        $payload = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:8', 'max:255'],
            'role' => ['nullable', Rule::in([UserRole::TenantAdmin->value, UserRole::Sales->value])],
            'template_key' => ['nullable', Rule::in($templateKeys)],
            'role_ids' => ['nullable', 'array', 'max:20'],
            'role_ids.*' => [
                'integer',
                Rule::exists('tenant_roles', 'id')->where(
                    fn ($builder) => $builder->where('tenant_id', $tenantId)
                ),
            ],
            'availability' => ['nullable', 'array'],
            'availability.timezone' => ['nullable', 'string', 'max:64'],
            'availability.status' => ['nullable', Rule::in(['available', 'offline', 'away', 'unavailable'])],
            'availability.is_online' => ['nullable', 'boolean'],
            'availability.offline' => ['nullable', 'boolean'],
            'availability.max_active_leads' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'availability.working_hours' => ['nullable', 'array'],
            'availability.working_hours.days' => ['nullable', 'array', 'max:7'],
            'availability.working_hours.days.*' => ['integer', 'min:1', 'max:7'],
            'availability.working_hours.start' => ['nullable', 'regex:/^(?:[01]\d|2[0-3]):[0-5]\d$/'],
            'availability.working_hours.end' => ['nullable', 'regex:/^(?:[01]\d|2[0-3]):[0-5]\d$/'],
            'availability.schedule' => ['nullable', 'array'],
            'availability.holidays' => ['nullable', 'array', 'max:365'],
            'booking_link' => ['nullable', 'url', 'max:2000'],
        ]);

        $templateKey = is_string($payload['template_key'] ?? null)
            ? trim($payload['template_key'])
            : null;

        $role = is_string($payload['role'] ?? null)
            ? trim($payload['role'])
            : null;

        if ($templateKey === null || $templateKey === '') {
            $templateKey = $role === UserRole::TenantAdmin->value ? 'admin' : 'sales';
        }

        if ($role === null || $role === '') {
            $role = $templateKey === 'admin'
                ? UserRole::TenantAdmin->value
                : UserRole::Sales->value;
        }

        $actor = $request->user();

        $user = DB::transaction(function () use ($actor, $payload, $role, $templateKey, $templateService, $tenantId): User {
            $availability = $this->normalizeAvailabilityPayload(
                is_array($payload['availability'] ?? null) ? $payload['availability'] : [],
            );

            $settings = [];

            if ($availability !== []) {
                data_set($settings, 'assignment.availability', $availability);
            }

            if (is_string($payload['booking_link'] ?? null) && trim((string) $payload['booking_link']) !== '') {
                data_set($settings, 'booking.link', trim((string) $payload['booking_link']));
            }

            $user = User::query()
                ->withoutTenancy()
                ->create([
                    'tenant_id' => $tenantId,
                    'name' => trim((string) $payload['name']),
                    'email' => Str::lower(trim((string) $payload['email'])),
                    'role' => $role,
                    'password' => (string) $payload['password'],
                    'is_super_admin' => false,
                    'settings' => $settings,
                    'last_seen_at' => now(),
                ]);

            $roleIds = collect($payload['role_ids'] ?? [])
                ->map(static fn ($id): int => (int) $id)
                ->filter(static fn (int $id): bool => $id > 0)
                ->unique()
                ->values();

            if ($roleIds->isEmpty()) {
                $templates = $templateService->ensureTenantTemplates($tenantId, $actor?->id);
                $templateRole = $templates->get($templateKey);

                if ($templateRole !== null) {
                    $roleIds = collect([(int) $templateRole->id]);
                }
            }

            if ($roleIds->isNotEmpty()) {
                $user->tenantRoles()->syncWithoutDetaching(
                    $roleIds
                        ->mapWithKeys(static fn (int $id): array => [$id => ['tenant_id' => $tenantId]])
                        ->all()
                );
            }

            return $user;
        });

        $user->load([
            'tenantRoles' => fn ($query) => $query
                ->where('tenant_roles.tenant_id', $tenantId)
                ->select(['tenant_roles.id', 'tenant_roles.name', 'tenant_roles.slug']),
        ]);

        return response()->json([
            'message' => 'User created successfully.',
            'user' => [
                ...$user->only(['id', 'tenant_id', 'name', 'email', 'role', 'created_at', 'updated_at']),
                'availability' => $this->extractAvailabilitySettings($user),
                'booking_link' => $this->extractBookingLink($user),
            ],
            'assigned_roles' => $user->tenantRoles->map(
                static fn ($roleModel): array => $roleModel->only(['id', 'name', 'slug'])
            )->values(),
        ], 201);
    }

    /**
     * Update one user assignment availability profile.
     */
    public function updateAvailability(Request $request, User $user): JsonResponse
    {
        $this->authorizePermission($request, 'roles.assign');

        $tenantId = $this->resolveTenantIdStrict($request);

        if ((int) $user->tenant_id !== $tenantId) {
            abort(403, 'User does not belong to active tenant.');
        }

        $payload = $request->validate([
            'availability' => ['required', 'array'],
            'availability.timezone' => ['nullable', 'string', 'max:64'],
            'availability.status' => ['nullable', Rule::in(['available', 'offline', 'away', 'unavailable'])],
            'availability.is_online' => ['nullable', 'boolean'],
            'availability.offline' => ['nullable', 'boolean'],
            'availability.max_active_leads' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'availability.working_hours' => ['nullable', 'array'],
            'availability.working_hours.days' => ['nullable', 'array', 'max:7'],
            'availability.working_hours.days.*' => ['integer', 'min:1', 'max:7'],
            'availability.working_hours.start' => ['nullable', 'regex:/^(?:[01]\d|2[0-3]):[0-5]\d$/'],
            'availability.working_hours.end' => ['nullable', 'regex:/^(?:[01]\d|2[0-3]):[0-5]\d$/'],
            'availability.schedule' => ['nullable', 'array'],
            'availability.holidays' => ['nullable', 'array', 'max:365'],
            'last_seen_at' => ['nullable', 'date'],
        ]);

        $existingAvailability = $this->extractAvailabilitySettings($user);
        $mergedAvailability = $this->normalizeAvailabilityPayload(
            array_merge($existingAvailability, is_array($payload['availability']) ? $payload['availability'] : [])
        );
        $settings = is_array($user->settings) ? $user->settings : [];
        data_set($settings, 'assignment.availability', $mergedAvailability);

        $updates = ['settings' => $settings];

        if (array_key_exists('last_seen_at', $payload)) {
            $updates['last_seen_at'] = $payload['last_seen_at'];
        } elseif (($mergedAvailability['is_online'] ?? null) === true) {
            $updates['last_seen_at'] = now();
        }

        $user->forceFill($updates)->save();

        return response()->json([
            'message' => 'User availability updated successfully.',
            'user' => [
                ...$user->refresh()->only(['id', 'tenant_id', 'name', 'email', 'role', 'last_seen_at']),
                'availability' => $this->extractAvailabilitySettings($user->refresh()),
                'booking_link' => $this->extractBookingLink($user->refresh()),
            ],
        ]);
    }

    /**
     * Update one user booking link profile.
     */
    public function updateBookingLink(Request $request, User $user): JsonResponse
    {
        $this->authorizePermission($request, 'settings.update', requireTenantContext: false);

        $tenantId = $this->resolveTenantIdStrict($request);

        if ((int) $user->tenant_id !== $tenantId) {
            abort(403, 'User does not belong to active tenant.');
        }

        $payload = $request->validate([
            'booking_link' => ['nullable', 'url', 'max:2000'],
        ]);

        $settings = is_array($user->settings) ? $user->settings : [];
        $bookingLink = is_string($payload['booking_link'] ?? null)
            ? trim((string) $payload['booking_link'])
            : '';

        if ($bookingLink !== '') {
            data_set($settings, 'booking.link', $bookingLink);
        } else {
            data_forget($settings, 'booking.link');
        }

        $user->forceFill([
            'settings' => $settings,
        ])->save();

        return response()->json([
            'message' => 'User booking link updated successfully.',
            'user' => [
                ...$user->refresh()->only(['id', 'tenant_id', 'name', 'email', 'role', 'last_seen_at']),
                'availability' => $this->extractAvailabilitySettings($user->refresh()),
                'booking_link' => $this->extractBookingLink($user->refresh()),
            ],
        ]);
    }

    /**
     * Resolve tenant id or fail.
     */
    private function resolveTenantIdStrict(Request $request): int
    {
        $tenantId = $this->resolveTenantIdForPermission($request, $request->user());

        if ($tenantId !== null && Tenant::query()->whereKey($tenantId)->exists()) {
            return $tenantId;
        }

        abort(422, 'Tenant context is required.');
    }

    /**
     * @return array<string, mixed>
     */
    private function extractAvailabilitySettings(User $user): array
    {
        $settings = is_array($user->settings) ? $user->settings : [];
        $availability = data_get($settings, 'assignment.availability');

        if (! is_array($availability)) {
            return [];
        }

        return $availability;
    }

    private function extractBookingLink(User $user): ?string
    {
        $settings = is_array($user->settings) ? $user->settings : [];
        $bookingLink = data_get($settings, 'booking.link');

        if (! is_string($bookingLink)) {
            return null;
        }

        $bookingLink = trim($bookingLink);

        return $bookingLink !== '' ? $bookingLink : null;
    }

    /**
     * Normalize user availability payload.
     *
     * @param array<string, mixed> $availability
     * @return array<string, mixed>
     */
    private function normalizeAvailabilityPayload(array $availability): array
    {
        $normalized = [];

        if (is_string($availability['timezone'] ?? null) && trim((string) $availability['timezone']) !== '') {
            $normalized['timezone'] = trim((string) $availability['timezone']);
        }

        if (is_string($availability['status'] ?? null) && trim((string) $availability['status']) !== '') {
            $normalized['status'] = mb_strtolower(trim((string) $availability['status']));
        }

        if (array_key_exists('is_online', $availability)) {
            $normalized['is_online'] = (bool) $availability['is_online'];
        }

        if (array_key_exists('offline', $availability)) {
            $normalized['offline'] = (bool) $availability['offline'];
        }

        if (is_numeric($availability['max_active_leads'] ?? null) && (int) $availability['max_active_leads'] > 0) {
            $normalized['max_active_leads'] = (int) $availability['max_active_leads'];
        }

        if (is_array($availability['working_hours'] ?? null)) {
            $workingHours = $availability['working_hours'];

            $normalizedWorkingHours = [
                'days' => collect(is_array($workingHours['days'] ?? null) ? $workingHours['days'] : [])
                    ->map(static fn (mixed $day): int => (int) $day)
                    ->filter(static fn (int $day): bool => $day >= 1 && $day <= 7)
                    ->unique()
                    ->values()
                    ->all(),
                'start' => is_string($workingHours['start'] ?? null) ? trim((string) $workingHours['start']) : null,
                'end' => is_string($workingHours['end'] ?? null) ? trim((string) $workingHours['end']) : null,
            ];

            $normalized['working_hours'] = $normalizedWorkingHours;
        }

        if (is_array($availability['schedule'] ?? null)) {
            $normalized['schedule'] = $availability['schedule'];
        }

        if (is_array($availability['holidays'] ?? null)) {
            $normalized['holidays'] = array_values($availability['holidays']);
        }

        return $normalized;
    }
}
