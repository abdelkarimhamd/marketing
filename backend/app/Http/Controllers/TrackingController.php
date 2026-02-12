<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Services\MessageStatusService;
use App\Support\MessageTrackingToken;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TrackingController extends Controller
{
    /**
     * Handle email open pixel tracking request.
     */
    public function open(
        string $token,
        Request $request,
        MessageTrackingToken $trackingToken,
        MessageStatusService $statusService
    ): Response {
        $payload = $trackingToken->parse($token);

        if (! is_array($payload) || ($payload['action'] ?? null) !== 'open') {
            abort(404, 'Invalid tracking token.');
        }

        $message = $this->resolveTrackedMessage($payload);

        if ($message === null) {
            abort(404, 'Tracked message not found.');
        }

        $statusService->markOpenedFromTracking($message, [
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        $pixel = base64_decode(
            'R0lGODlhAQABAPAAAAAAAAAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==',
            true
        );

        return response($pixel !== false ? $pixel : '', 200, [
            'Content-Type' => 'image/gif',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }

    /**
     * Handle tracked click redirect.
     */
    public function click(
        string $token,
        Request $request,
        MessageTrackingToken $trackingToken,
        MessageStatusService $statusService
    ): RedirectResponse {
        $payload = $trackingToken->parse($token);

        if (! is_array($payload) || ($payload['action'] ?? null) !== 'click') {
            abort(404, 'Invalid tracking token.');
        }

        $targetUrl = (string) ($payload['url'] ?? '');

        if (! $this->isValidRedirectUrl($targetUrl)) {
            abort(404, 'Invalid tracking destination.');
        }

        $message = $this->resolveTrackedMessage($payload);

        if ($message === null) {
            abort(404, 'Tracked message not found.');
        }

        $statusService->markClickedFromTracking($message, [
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'url' => $targetUrl,
        ]);

        return redirect()->away($targetUrl, 302);
    }

    /**
     * Resolve tracked message securely from token payload.
     *
     * @param array<string, mixed> $payload
     */
    private function resolveTrackedMessage(array $payload): ?Message
    {
        $tenantId = isset($payload['tenant_id']) && is_numeric($payload['tenant_id'])
            ? (int) $payload['tenant_id']
            : null;

        $messageId = isset($payload['message_id']) && is_numeric($payload['message_id'])
            ? (int) $payload['message_id']
            : null;

        if ($tenantId === null || $tenantId <= 0 || $messageId === null || $messageId <= 0) {
            return null;
        }

        return Message::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->whereKey($messageId)
            ->where('channel', 'email')
            ->first();
    }

    /**
     * Validate redirect URL safety.
     */
    private function isValidRedirectUrl(string $url): bool
    {
        $trimmed = trim($url);

        if ($trimmed === '') {
            return false;
        }

        $scheme = mb_strtolower((string) parse_url($trimmed, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true);
    }
}
