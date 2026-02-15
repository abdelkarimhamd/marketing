<?php

namespace App\Messaging\Providers;

use App\Messaging\Contracts\SmsProviderInterface;
use App\Messaging\DTO\OutgoingMessageData;
use App\Messaging\DTO\ProviderSendResult;
use Illuminate\Support\Facades\Http;
use Throwable;

class TwilioSmsProvider implements SmsProviderInterface
{
    /**
     * Send SMS using Twilio's REST API.
     */
    public function send(OutgoingMessageData $message): ProviderSendResult
    {
        $accountSid = (string) config('messaging.twilio.account_sid', '');
        $authToken = (string) config('messaging.twilio.auth_token', '');
        $from = is_string($message->from) && trim((string) $message->from) !== ''
            ? trim((string) $message->from)
            : (string) config('messaging.twilio.from', '');

        if ($accountSid === '' || $authToken === '' || $from === '') {
            return ProviderSendResult::failed('twilio', 'Twilio credentials are not configured.');
        }

        $body = (string) ($message->body ?? '');

        if ($body === '') {
            return ProviderSendResult::failed('twilio', 'SMS body is empty.');
        }

        try {
            $url = "https://api.twilio.com/2010-04-01/Accounts/{$accountSid}/Messages.json";

            $payload = [
                'To' => $message->to,
                'From' => $from,
                'Body' => $body,
            ];

            $statusCallback = (string) config('messaging.twilio.status_callback_url', '');
            if ($statusCallback !== '') {
                $payload['StatusCallback'] = $statusCallback;
            }

            $response = Http::asForm()
                ->withBasicAuth($accountSid, $authToken)
                ->post($url, $payload);

            if (! $response->successful()) {
                return ProviderSendResult::failed(
                    provider: 'twilio',
                    errorMessage: 'Twilio request failed: '.$response->body(),
                    meta: [
                        'http_status' => $response->status(),
                    ],
                );
            }

            $data = $response->json();
            $providerMessageId = is_array($data) ? ($data['sid'] ?? null) : null;

            return ProviderSendResult::accepted(
                provider: 'twilio',
                providerMessageId: is_string($providerMessageId) ? $providerMessageId : null,
                status: 'sent',
                meta: [
                    'http_status' => $response->status(),
                ],
            );
        } catch (Throwable $exception) {
            return ProviderSendResult::failed('twilio', $exception->getMessage());
        }
    }
}
