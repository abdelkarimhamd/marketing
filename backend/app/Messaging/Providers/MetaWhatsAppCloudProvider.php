<?php

namespace App\Messaging\Providers;

use App\Messaging\Contracts\WhatsAppProviderInterface;
use App\Messaging\DTO\OutgoingMessageData;
use App\Messaging\DTO\ProviderSendResult;
use Illuminate\Support\Facades\Http;
use Throwable;

class MetaWhatsAppCloudProvider implements WhatsAppProviderInterface
{
    /**
     * Send WhatsApp message using Meta WhatsApp Cloud API.
     */
    public function send(OutgoingMessageData $message): ProviderSendResult
    {
        $token = (string) config('messaging.meta_whatsapp.token', '');
        $phoneNumberId = (string) config('messaging.meta_whatsapp.phone_number_id', '');
        $version = (string) config('messaging.meta_whatsapp.version', 'v20.0');

        if ($token === '' || $phoneNumberId === '') {
            return ProviderSendResult::failed('meta', 'Meta WhatsApp Cloud API credentials are not configured.');
        }

        try {
            $url = "https://graph.facebook.com/{$version}/{$phoneNumberId}/messages";
            $response = Http::withToken($token)
                ->post($url, $this->buildPayload($message));

            if (! $response->successful()) {
                return ProviderSendResult::failed(
                    provider: 'meta',
                    errorMessage: 'Meta WhatsApp request failed: '.$response->body(),
                    meta: [
                        'http_status' => $response->status(),
                    ],
                );
            }

            $data = $response->json();
            $providerMessageId = null;

            if (is_array($data)) {
                $providerMessageId = $data['messages'][0]['id'] ?? null;
            }

            return ProviderSendResult::accepted(
                provider: 'meta',
                providerMessageId: is_string($providerMessageId) ? $providerMessageId : null,
                status: 'sent',
                meta: [
                    'http_status' => $response->status(),
                ],
            );
        } catch (Throwable $exception) {
            return ProviderSendResult::failed('meta', $exception->getMessage());
        }
    }

    /**
     * Build payload from the message meta/body.
     *
     * @return array<string, mixed>
     */
    private function buildPayload(OutgoingMessageData $message): array
    {
        $meta = is_array($message->meta) ? $message->meta : [];
        $templateName = $meta['template_name'] ?? null;
        $variables = is_array($meta['variables'] ?? null) ? $meta['variables'] : [];
        $language = (string) ($meta['language'] ?? config('messaging.meta_whatsapp.default_language', 'en_US'));

        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $message->to,
        ];

        if (is_string($templateName) && trim($templateName) !== '') {
            $template = [
                'name' => $templateName,
                'language' => [
                    'code' => $language,
                ],
            ];

            $parameters = [];
            foreach (array_values($variables) as $value) {
                if (is_array($value) || is_object($value)) {
                    continue;
                }

                $parameters[] = [
                    'type' => 'text',
                    'text' => (string) $value,
                ];
            }

            if ($parameters !== []) {
                $template['components'] = [[
                    'type' => 'body',
                    'parameters' => $parameters,
                ]];
            }

            $payload['type'] = 'template';
            $payload['template'] = $template;

            return $payload;
        }

        $payload['type'] = 'text';
        $payload['text'] = [
            'preview_url' => false,
            'body' => (string) ($message->body ?? ''),
        ];

        return $payload;
    }
}
