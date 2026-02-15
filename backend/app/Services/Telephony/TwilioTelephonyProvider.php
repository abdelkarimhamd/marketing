<?php

namespace App\Services\Telephony;

use App\Models\Lead;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Http;

class TwilioTelephonyProvider implements TelephonyProviderInterface
{
    public function startCall(Tenant $tenant, User $user, Lead $lead, array $payload = []): array
    {
        $accountSid = (string) (config('services.twilio.account_sid') ?: config('services.twilio.sid', ''));
        $authToken = (string) (config('services.twilio.auth_token') ?: config('services.twilio.token', ''));
        $from = trim((string) ($payload['from'] ?? (config('services.twilio.phone_number') ?: config('services.twilio.from', ''))));
        $to = trim((string) ($payload['to'] ?? $lead->phone ?? ''));

        if ($accountSid === '' || $authToken === '' || $from === '' || $to === '') {
            throw new \RuntimeException('Twilio telephony is not fully configured.');
        }

        $callbackUrl = trim((string) ($payload['status_callback_url'] ?? route('webhooks.telephony.twilio')));
        $twimlUrl = trim((string) ($payload['twiml_url'] ?? route('webhooks.telephony.twilio')));

        $response = Http::asForm()
            ->withBasicAuth($accountSid, $authToken)
            ->post("https://api.twilio.com/2010-04-01/Accounts/{$accountSid}/Calls.json", [
                'From' => $from,
                'To' => $to,
                'Url' => $twimlUrl,
                'StatusCallback' => $callbackUrl,
                'StatusCallbackMethod' => 'POST',
                'StatusCallbackEvent' => ['initiated', 'ringing', 'answered', 'completed'],
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Twilio call start failed: '.$response->status().' '.$response->body());
        }

        $body = is_array($response->json()) ? $response->json() : [];

        return [
            'provider' => $this->key(),
            'provider_call_id' => (string) ($body['sid'] ?? 'twilio_'.uniqid()),
            'status' => (string) ($body['status'] ?? 'queued'),
            'meta' => [
                'direction' => $body['direction'] ?? null,
                'from' => $from,
                'to' => $to,
            ],
        ];
    }

    public function issueAccessToken(Tenant $tenant, User $user): array
    {
        $apiKey = trim((string) config('services.twilio.api_key'));
        $apiSecret = trim((string) config('services.twilio.api_secret'));
        $appSid = trim((string) config('services.twilio.app_sid'));

        if ($apiKey === '' || $apiSecret === '' || $appSid === '') {
            throw new \RuntimeException('Twilio access token credentials are missing.');
        }

        $expiresAt = now()->addMinutes(30);

        $payload = [
            'iss' => $apiKey,
            'sub' => config('services.twilio.account_sid') ?: config('services.twilio.sid'),
            'exp' => $expiresAt->timestamp,
            'iat' => now()->timestamp,
            'grants' => [
                'identity' => sprintf('tenant_%d_user_%d', (int) $tenant->id, (int) $user->id),
                'voice' => [
                    'outgoing' => ['application_sid' => $appSid],
                    'incoming' => ['allow' => true],
                ],
            ],
        ];

        $header = ['typ' => 'JWT', 'alg' => 'HS256'];
        $token = $this->base64Url(json_encode($header, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}')
            .'.'.$this->base64Url(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

        $signature = hash_hmac('sha256', $token, $apiSecret, true);
        $token .= '.'.$this->base64Url($signature);

        return [
            'token' => $token,
            'expires_at' => $expiresAt->toIso8601String(),
            'provider' => $this->key(),
        ];
    }

    public function mapWebhookPayload(Tenant $tenant, array $payload): ?array
    {
        $providerCallId = trim((string) ($payload['CallSid'] ?? ''));

        if ($providerCallId === '') {
            return null;
        }

        $statusMap = [
            'queued' => 'queued',
            'initiated' => 'queued',
            'ringing' => 'ringing',
            'in-progress' => 'in_progress',
            'completed' => 'completed',
            'busy' => 'failed',
            'failed' => 'failed',
            'no-answer' => 'failed',
            'canceled' => 'failed',
        ];

        $providerStatus = trim(mb_strtolower((string) ($payload['CallStatus'] ?? '')));
        $mappedStatus = $statusMap[$providerStatus] ?? 'in_progress';

        return [
            'provider_call_id' => $providerCallId,
            'status' => $mappedStatus,
            'duration' => is_numeric($payload['CallDuration'] ?? null) ? (int) $payload['CallDuration'] : null,
            'recording_url' => is_string($payload['RecordingUrl'] ?? null) ? (string) $payload['RecordingUrl'] : null,
            'meta' => [
                'from' => $payload['From'] ?? null,
                'to' => $payload['To'] ?? null,
                'direction' => $payload['Direction'] ?? null,
                'raw_status' => $providerStatus,
            ],
        ];
    }

    public function key(): string
    {
        return 'twilio';
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
