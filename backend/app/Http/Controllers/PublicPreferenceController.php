<?php

namespace App\Http\Controllers;

use App\Models\LeadPreference;
use App\Services\ConsentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PublicPreferenceController extends Controller
{
    /**
     * Show preference center details by secure token.
     */
    public function show(string $token, Request $request): View|JsonResponse
    {
        $preference = $this->resolvePreference($token);

        $payload = [
            'token' => $preference->token,
            'tenant_id' => $preference->tenant_id,
            'lead_id' => $preference->lead_id,
            'email' => $preference->email,
            'phone' => $preference->phone,
            'locale' => $preference->locale,
            'channels' => is_array($preference->channels) ? $preference->channels : [],
            'topics' => is_array($preference->topics) ? $preference->topics : [],
            'last_confirmed_at' => $preference->last_confirmed_at,
        ];

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'preference' => $payload,
            ]);
        }

        return view('preferences.center', [
            'preference' => $payload,
        ]);
    }

    /**
     * Update channel/topic preferences from public center.
     */
    public function update(string $token, Request $request, ConsentService $consentService): JsonResponse
    {
        $preference = $this->resolvePreference($token)->load('lead');

        $payload = $request->validate([
            'locale' => ['nullable', 'string', 'max:12'],
            'channels' => ['nullable', 'array'],
            'channels.email' => ['nullable', 'boolean'],
            'channels.sms' => ['nullable', 'boolean'],
            'channels.whatsapp' => ['nullable', 'boolean'],
            'topics' => ['nullable', 'array', 'max:50'],
            'topics.*' => ['string', 'max:100'],
        ]);

        $channels = is_array($payload['channels'] ?? null) ? $payload['channels'] : [];
        $topics = is_array($payload['topics'] ?? null) ? $payload['topics'] : [];
        $locale = isset($payload['locale']) ? (string) $payload['locale'] : null;

        $updated = $consentService->updatePreference($preference, $channels, $topics, $locale);

        if ($preference->lead !== null) {
            foreach ($channels as $channel => $granted) {
                if (! is_bool($granted)) {
                    continue;
                }

                $consentService->recordLeadConsent(
                    lead: $preference->lead,
                    channel: (string) $channel,
                    granted: $granted,
                    source: 'preference_center',
                    proofMethod: 'public_preference_page',
                    proofRef: $token,
                    context: ['topics' => $topics],
                    ipAddress: $request->ip(),
                    userAgent: $request->userAgent(),
                );
            }
        }

        return response()->json([
            'message' => 'Preferences updated successfully.',
            'preference' => $updated,
        ]);
    }

    private function resolvePreference(string $token): LeadPreference
    {
        $preference = LeadPreference::query()
            ->withoutTenancy()
            ->where('token', trim($token))
            ->first();

        if ($preference === null) {
            abort(404, 'Preference token is invalid or expired.');
        }

        return $preference;
    }
}

