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
     * @var list<string>
     */
    private const MEDIA_MESSAGE_TYPES = ['image', 'video', 'audio', 'document'];

    /**
     * Send WhatsApp message using Meta WhatsApp Cloud API.
     */
    public function send(OutgoingMessageData $message): ProviderSendResult
    {
        $token = (string) config('messaging.meta_whatsapp.token', '');
        $meta = is_array($message->meta) ? $message->meta : [];
        $phoneNumberId = trim((string) ($meta['phone_number_id'] ?? data_get($meta, 'whatsapp.phone_number_id', '')));

        if ($phoneNumberId === '') {
            $phoneNumberId = (string) config('messaging.meta_whatsapp.phone_number_id', '');
        }

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
        $templateName = is_string($meta['template_name'] ?? null)
            ? trim((string) $meta['template_name'])
            : '';
        $messageType = mb_strtolower(trim((string) ($meta['message_type'] ?? '')));

        if ($messageType === '') {
            $messageType = $templateName !== '' ? 'template' : 'text';
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $message->to,
        ];

        if ($messageType === 'template') {
            return $this->buildTemplatePayload($payload, $message, $meta, $templateName);
        }

        if (in_array($messageType, self::MEDIA_MESSAGE_TYPES, true)) {
            return $this->buildMediaPayload($payload, $message, $meta, $messageType);
        }

        if ($messageType === 'catalog') {
            return $this->buildCatalogPayload($payload, $message, $meta);
        }

        if ($messageType === 'catalog_list') {
            return $this->buildCatalogListPayload($payload, $message, $meta);
        }

        if ($messageType === 'carousel') {
            return $this->buildCarouselFallbackPayload($payload, $message, $meta);
        }

        return $this->buildTextPayload($payload, $message, $meta);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private function buildTemplatePayload(
        array $payload,
        OutgoingMessageData $message,
        array $meta,
        string $templateName
    ): array {
        if ($templateName === '') {
            throw new \RuntimeException('WhatsApp template_name is required for template messages.');
        }

        $language = trim((string) ($meta['language'] ?? config('messaging.meta_whatsapp.default_language', 'en_US')));
        $template = [
            'name' => $templateName,
            'language' => [
                'code' => $language !== '' ? $language : 'en_US',
            ],
        ];

        $components = [];
        if (is_array($meta['components'] ?? null)) {
            $components = array_values(array_filter(
                $meta['components'],
                static fn (mixed $item): bool => is_array($item)
            ));
        }

        if ($components === []) {
            $variables = is_array($meta['variables'] ?? null) ? $meta['variables'] : [];
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
                $components[] = [
                    'type' => 'body',
                    'parameters' => $parameters,
                ];
            }
        }

        if ($components !== []) {
            $template['components'] = $components;
        }

        $payload['type'] = 'template';
        $payload['template'] = $template;

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private function buildMediaPayload(
        array $payload,
        OutgoingMessageData $message,
        array $meta,
        string $messageType
    ): array {
        $media = is_array($meta['media'] ?? null) ? $meta['media'] : [];
        $providerMediaId = trim((string) ($media['provider_media_id'] ?? ''));
        $link = trim((string) ($media['link'] ?? ''));

        if ($providerMediaId === '' && $link === '') {
            throw new \RuntimeException(
                'WhatsApp media message requires media.link or media.provider_media_id.'
            );
        }

        $payload['type'] = $messageType;
        $payload[$messageType] = $providerMediaId !== ''
            ? ['id' => $providerMediaId]
            : ['link' => $link];

        $caption = trim((string) ($media['caption'] ?? $message->body ?? ''));
        if ($caption !== '' && in_array($messageType, ['image', 'video', 'document'], true)) {
            $payload[$messageType]['caption'] = $caption;
        }

        $filename = trim((string) ($media['filename'] ?? ''));
        if ($filename !== '' && $messageType === 'document') {
            $payload[$messageType]['filename'] = $filename;
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private function buildCatalogPayload(array $payload, OutgoingMessageData $message, array $meta): array
    {
        $catalog = is_array($meta['catalog'] ?? null) ? $meta['catalog'] : [];
        $catalogId = trim((string) ($catalog['catalog_id'] ?? ''));
        $productRetailerId = trim((string) ($catalog['product_retailer_id'] ?? ''));

        if ($catalogId === '' || $productRetailerId === '') {
            throw new \RuntimeException(
                'WhatsApp catalog message requires catalog.catalog_id and catalog.product_retailer_id.'
            );
        }

        $interactive = [
            'type' => 'product',
            'action' => [
                'catalog_id' => $catalogId,
                'product_retailer_id' => $productRetailerId,
            ],
        ];

        $bodyText = trim((string) ($message->body ?? $catalog['body'] ?? ''));
        if ($bodyText !== '') {
            $interactive['body'] = ['text' => $bodyText];
        }

        $headerText = trim((string) ($catalog['header'] ?? ''));
        if ($headerText !== '') {
            $interactive['header'] = [
                'type' => 'text',
                'text' => $this->truncate($headerText, 60),
            ];
        }

        $footerText = trim((string) ($catalog['footer'] ?? ''));
        if ($footerText !== '') {
            $interactive['footer'] = [
                'text' => $this->truncate($footerText, 60),
            ];
        }

        $payload['type'] = 'interactive';
        $payload['interactive'] = $interactive;

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private function buildCatalogListPayload(array $payload, OutgoingMessageData $message, array $meta): array
    {
        $catalog = is_array($meta['catalog'] ?? null) ? $meta['catalog'] : [];
        $catalogId = trim((string) ($catalog['catalog_id'] ?? ''));
        $sections = $this->normalizeCatalogSections($catalog['sections'] ?? null);

        if ($catalogId === '' || $sections === []) {
            throw new \RuntimeException(
                'WhatsApp catalog_list requires catalog.catalog_id and catalog.sections with product items.'
            );
        }

        $interactive = [
            'type' => 'product_list',
            'action' => [
                'catalog_id' => $catalogId,
                'sections' => $sections,
            ],
        ];

        $bodyText = trim((string) ($message->body ?? $catalog['body'] ?? ''));
        if ($bodyText !== '') {
            $interactive['body'] = ['text' => $bodyText];
        }

        $headerText = trim((string) ($catalog['header'] ?? ''));
        if ($headerText !== '') {
            $interactive['header'] = [
                'type' => 'text',
                'text' => $this->truncate($headerText, 60),
            ];
        }

        $footerText = trim((string) ($catalog['footer'] ?? ''));
        if ($footerText !== '') {
            $interactive['footer'] = [
                'text' => $this->truncate($footerText, 60),
            ];
        }

        $payload['type'] = 'interactive';
        $payload['interactive'] = $interactive;

        return $payload;
    }

    /**
     * Build an interactive list fallback from carousel-style cards.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private function buildCarouselFallbackPayload(array $payload, OutgoingMessageData $message, array $meta): array
    {
        $carousel = is_array($meta['carousel'] ?? null) ? $meta['carousel'] : [];
        $cards = is_array($carousel['cards'] ?? null) ? $carousel['cards'] : [];
        $rows = [];

        foreach (array_values($cards) as $index => $card) {
            if (! is_array($card)) {
                continue;
            }

            $id = trim((string) ($card['id'] ?? 'card_'.($index + 1)));
            if ($id === '') {
                $id = 'card_'.($index + 1);
            }

            $title = trim((string) ($card['title'] ?? $card['name'] ?? ('Option '.($index + 1))));
            if ($title === '') {
                $title = 'Option '.($index + 1);
            }

            $row = [
                'id' => $id,
                'title' => $this->truncate($title, 24),
            ];

            $description = trim((string) ($card['description'] ?? $card['body'] ?? ''));
            if ($description !== '') {
                $row['description'] = $this->truncate($description, 72);
            }

            $rows[] = $row;

            if (count($rows) >= 10) {
                break;
            }
        }

        if ($rows === []) {
            throw new \RuntimeException('WhatsApp carousel requires at least one card.');
        }

        $sectionTitle = trim((string) ($carousel['section_title'] ?? 'Options'));
        if ($sectionTitle === '') {
            $sectionTitle = 'Options';
        }

        $buttonText = trim((string) ($carousel['button_text'] ?? 'View options'));
        if ($buttonText === '') {
            $buttonText = 'View options';
        }

        $bodyText = trim((string) ($message->body ?? $carousel['body'] ?? 'Please choose an option.'));
        if ($bodyText === '') {
            $bodyText = 'Please choose an option.';
        }

        $interactive = [
            'type' => 'list',
            'body' => [
                'text' => $this->truncate($bodyText, 1024),
            ],
            'action' => [
                'button' => $this->truncate($buttonText, 20),
                'sections' => [[
                    'title' => $this->truncate($sectionTitle, 24),
                    'rows' => $rows,
                ]],
            ],
        ];

        $headerText = trim((string) ($carousel['header'] ?? ''));
        if ($headerText !== '') {
            $interactive['header'] = [
                'type' => 'text',
                'text' => $this->truncate($headerText, 60),
            ];
        }

        $footerText = trim((string) ($carousel['footer'] ?? ''));
        if ($footerText !== '') {
            $interactive['footer'] = [
                'text' => $this->truncate($footerText, 60),
            ];
        }

        $payload['type'] = 'interactive';
        $payload['interactive'] = $interactive;

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private function buildTextPayload(array $payload, OutgoingMessageData $message, array $meta): array
    {
        $body = trim((string) ($message->body ?? $meta['text'] ?? ''));

        if ($body === '') {
            throw new \RuntimeException('WhatsApp text message body is empty.');
        }

        $payload['type'] = 'text';
        $payload['text'] = [
            'preview_url' => false,
            'body' => $body,
        ];

        return $payload;
    }

    /**
     * @param mixed $rawSections
     * @return array<int, array<string, mixed>>
     */
    private function normalizeCatalogSections(mixed $rawSections): array
    {
        if (! is_array($rawSections)) {
            return [];
        }

        $sections = [];

        foreach ($rawSections as $section) {
            if (! is_array($section)) {
                continue;
            }

            $rawItems = $section['product_items'] ?? $section['items'] ?? [];
            if (! is_array($rawItems)) {
                continue;
            }

            $productItems = [];

            foreach ($rawItems as $item) {
                $id = '';

                if (is_array($item)) {
                    $id = trim((string) ($item['product_retailer_id'] ?? $item['id'] ?? ''));
                } elseif (is_scalar($item)) {
                    $id = trim((string) $item);
                }

                if ($id === '') {
                    continue;
                }

                $productItems[] = [
                    'product_retailer_id' => $id,
                ];
            }

            if ($productItems === []) {
                continue;
            }

            $payload = [
                'product_items' => $productItems,
            ];

            $title = trim((string) ($section['title'] ?? ''));
            if ($title !== '') {
                $payload['title'] = $this->truncate($title, 24);
            }

            $sections[] = $payload;
        }

        return $sections;
    }

    private function truncate(string $value, int $maxLength): string
    {
        $value = trim($value);

        if ($maxLength <= 0) {
            return '';
        }

        if (mb_strlen($value) <= $maxLength) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, $maxLength));
    }
}
