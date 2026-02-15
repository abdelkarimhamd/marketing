<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CheckoutSession;
use App\Models\TenantSubscription;
use App\Services\SelfServeSignupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicSignupController extends Controller
{
    /**
     * Public self-serve signup endpoint.
     */
    public function signup(Request $request, SelfServeSignupService $signupService): JsonResponse
    {
        $payload = $request->validate([
            'tenant_name' => ['required', 'string', 'max:150'],
            'tenant_slug' => ['nullable', 'string', 'max:120'],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
            'plan_slug' => ['nullable', 'string', 'max:120'],
            'coupon_code' => ['nullable', 'string', 'max:120'],
        ]);

        $result = $signupService->signup($payload);

        return response()->json([
            'message' => 'Signup completed. Tenant and admin account created.',
            'tenant' => $result['tenant'],
            'user' => $result['user'],
            'subscription' => $result['subscription'],
            'checkout_session' => $result['checkout_session'],
        ], 201);
    }

    /**
     * Billing webhook callback for subscription lifecycle sync.
     */
    public function billingWebhook(Request $request, string $provider): JsonResponse
    {
        $provider = mb_strtolower(trim($provider));
        $payload = $request->all();

        if ($provider !== 'stripe') {
            return response()->json([
                'message' => 'Unsupported billing provider.',
            ], 422);
        }

        $sessionId = (string) (
            data_get($payload, 'data.object.id')
            ?? $payload['session_id']
            ?? ''
        );

        if ($sessionId === '') {
            return response()->json([
                'message' => 'Session id is missing.',
            ], 422);
        }

        $checkout = CheckoutSession::query()
            ->withoutTenancy()
            ->where('provider', 'stripe')
            ->where('provider_session_id', $sessionId)
            ->first();

        if ($checkout === null) {
            return response()->json([
                'message' => 'Checkout session not found.',
            ], 404);
        }

        $status = (string) (data_get($payload, 'data.object.payment_status') ?? 'completed');
        $subscriptionStatus = $status === 'paid' ? 'active' : 'past_due';

        $checkout->forceFill([
            'status' => $status,
            'completed_at' => now(),
            'payload' => $payload,
        ])->save();

        TenantSubscription::query()
            ->withoutTenancy()
            ->where('tenant_id', $checkout->tenant_id)
            ->latest('id')
            ->limit(1)
            ->update([
                'status' => $subscriptionStatus,
                'metadata' => ['last_webhook_status' => $status],
            ]);

        return response()->json([
            'message' => 'Billing webhook processed.',
        ]);
    }
}

