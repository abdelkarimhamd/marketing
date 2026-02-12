<?php

namespace App\Services;

use App\Models\Message;
use App\Support\MessageTrackingToken;

class MessageTrackingService
{
    public function __construct(
        private readonly MessageTrackingToken $trackingToken,
    ) {
    }

    /**
     * Decorate outgoing email body with open pixel and click tracking links.
     */
    public function decorateEmailMessage(Message $message): Message
    {
        if ($message->channel !== 'email') {
            return $message;
        }

        $body = (string) ($message->body ?? '');
        $meta = is_array($message->meta) ? $message->meta : [];
        $trackingMeta = is_array($meta['tracking'] ?? null) ? $meta['tracking'] : [];

        if (($trackingMeta['prepared'] ?? false) === true) {
            return $message;
        }

        $clickLinks = 0;

        if ((bool) config('messaging.tracking.click_tracking_enabled', true)) {
            [$body, $clickLinks] = $this->injectClickTracking($message, $body);
        }

        $openUrl = null;

        if ((bool) config('messaging.tracking.open_pixel_enabled', true)) {
            $openUrl = $this->openTrackingUrl($message);
            $body = $this->injectOpenPixel($body, $openUrl);
        }

        $meta['tracking'] = [
            'prepared' => true,
            'click_links' => $clickLinks,
            'open_url' => $openUrl,
            'prepared_at' => now()->toIso8601String(),
        ];

        $message->forceFill([
            'body' => $body,
            'meta' => $meta,
        ])->save();

        return $message->refresh();
    }

    /**
     * Build open-tracking URL for this message.
     */
    public function openTrackingUrl(Message $message): string
    {
        $token = $this->trackingToken->makeOpenToken(
            tenantId: (int) $message->tenant_id,
            messageId: (int) $message->id,
        );

        return route('tracking.open', ['token' => $token], true);
    }

    /**
     * Build click-tracking URL for this message and destination URL.
     */
    public function clickTrackingUrl(Message $message, string $destinationUrl): string
    {
        $token = $this->trackingToken->makeClickToken(
            tenantId: (int) $message->tenant_id,
            messageId: (int) $message->id,
            targetUrl: $destinationUrl,
        );

        return route('tracking.click', ['token' => $token], true);
    }

    /**
     * Rewrite all HTTP(S) href attributes with click-tracking redirects.
     *
     * @return array{string, int}
     */
    private function injectClickTracking(Message $message, string $html): array
    {
        $count = 0;

        $rewritten = preg_replace_callback(
            '/href\s*=\s*(["\'])(.*?)\1/i',
            function (array $matches) use ($message, &$count): string {
                $quote = (string) ($matches[1] ?? '"');
                $url = (string) ($matches[2] ?? '');

                if (! $this->isTrackableUrl($url)) {
                    return (string) $matches[0];
                }

                $count++;
                $trackedUrl = $this->clickTrackingUrl($message, $url);

                return 'href='.$quote.$trackedUrl.$quote;
            },
            $html
        );

        return [
            is_string($rewritten) ? $rewritten : $html,
            $count,
        ];
    }

    /**
     * Append open pixel into the email HTML.
     */
    private function injectOpenPixel(string $html, string $pixelUrl): string
    {
        $pixel = '<img src="'.$pixelUrl.'" alt="" width="1" height="1" style="display:none;" />';

        if (stripos($html, '</body>') !== false) {
            return (string) preg_replace('/<\/body>/i', $pixel.'</body>', $html, 1);
        }

        return $html.$pixel;
    }

    /**
     * Only track outbound HTTP/HTTPS links.
     */
    private function isTrackableUrl(string $url): bool
    {
        $trimmed = trim($url);

        if ($trimmed === '') {
            return false;
        }

        $scheme = parse_url($trimmed, PHP_URL_SCHEME);

        return in_array(mb_strtolower((string) $scheme), ['http', 'https'], true);
    }
}
