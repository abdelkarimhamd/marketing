<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeamController extends Controller
{
    /**
     * List teams in active tenant with booking links.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'leads.view');

        $tenantId = $this->resolveTenantIdStrict($request);
        $teams = Team::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->orderBy('name')
            ->get();

        return response()->json([
            'teams' => $teams->map(function (Team $team): array {
                return [
                    ...$team->only(['id', 'tenant_id', 'name', 'slug', 'is_active']),
                    'booking_link' => $this->extractBookingLink($team),
                ];
            })->values(),
        ]);
    }

    /**
     * Update one team booking link.
     */
    public function updateBookingLink(Request $request, Team $team): JsonResponse
    {
        $this->authorizePermission($request, 'settings.update', requireTenantContext: false);

        $tenantId = $this->resolveTenantIdStrict($request);

        if ((int) $team->tenant_id !== $tenantId) {
            abort(403, 'Team does not belong to active tenant.');
        }

        $payload = $request->validate([
            'booking_link' => ['nullable', 'url', 'max:2000'],
        ]);

        $settings = is_array($team->settings) ? $team->settings : [];

        if (array_key_exists('booking_link', $payload) && is_string($payload['booking_link'])) {
            $bookingLink = trim((string) $payload['booking_link']);

            if ($bookingLink !== '') {
                data_set($settings, 'booking.link', $bookingLink);
            } else {
                data_forget($settings, 'booking.link');
            }
        } else {
            data_forget($settings, 'booking.link');
        }

        $team->forceFill(['settings' => $settings])->save();

        return response()->json([
            'message' => 'Team booking link updated successfully.',
            'team' => [
                ...$team->refresh()->only(['id', 'tenant_id', 'name', 'slug', 'is_active']),
                'booking_link' => $this->extractBookingLink($team->refresh()),
            ],
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

    private function extractBookingLink(Team $team): ?string
    {
        $settings = is_array($team->settings) ? $team->settings : [];
        $bookingLink = data_get($settings, 'booking.link');

        if (! is_string($bookingLink)) {
            return null;
        }

        $bookingLink = trim($bookingLink);

        return $bookingLink !== '' ? $bookingLink : null;
    }
}
