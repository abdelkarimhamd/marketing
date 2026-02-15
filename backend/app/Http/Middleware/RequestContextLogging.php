<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class RequestContextLogging
{
    /**
     * Attach stable request context for all API requests.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $this->resolveRequestId($request);
        $request->attributes->set('request_id', $requestId);

        $context = $this->buildContext($request, $requestId);

        Log::withContext($context);
        Log::info('request.received', $context);

        $response = $next($request);
        $response->headers->set('X-Request-ID', $requestId);

        $statusCode = (int) $response->getStatusCode();
        $responseContext = array_merge($context, [
            'status_code' => $statusCode,
        ]);

        if ($statusCode >= 500) {
            Log::error('request.server_error', $responseContext);
        } elseif ($statusCode >= 400) {
            Log::warning('request.client_error', $responseContext);
        } else {
            Log::info('request.completed', $responseContext);
        }

        return $response;
    }

    private function resolveRequestId(Request $request): string
    {
        $incoming = $request->header('X-Request-ID', '');

        if (is_string($incoming) && trim($incoming) !== '') {
            return trim($incoming);
        }

        return (string) Str::uuid();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildContext(Request $request, string $requestId): array
    {
        $tenantId = $request->attributes->get('tenant_id');
        $route = $request->route();
        $provider = $route?->parameter('provider') ?? $request->input('provider');
        $messageId = $request->input('provider_message_id', $request->input('message_id'));
        $user = $request->user() ?? Auth::guard('sanctum')->user();

        return [
            'request_id' => $requestId,
            'tenant_id' => is_numeric($tenantId) ? (int) $tenantId : null,
            'user_id' => is_numeric($user?->id) ? (int) $user->id : null,
            'feature' => $this->resolveFeature($request),
            'provider' => is_scalar($provider) ? (string) $provider : null,
            'message_id' => is_scalar($messageId) ? (string) $messageId : null,
            'method' => $request->getMethod(),
            'path' => $request->path(),
        ];
    }

    private function resolveFeature(Request $request): string
    {
        $segments = $request->segments();

        if ($segments === []) {
            return 'root';
        }

        if (($segments[0] ?? null) !== 'api') {
            return $segments[0];
        }

        if (($segments[1] ?? null) === 'admin') {
            return isset($segments[2]) ? "admin.{$segments[2]}" : 'admin';
        }

        return isset($segments[1]) ? "public.{$segments[1]}" : 'api';
    }
}
