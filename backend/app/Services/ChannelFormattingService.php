<?php

namespace App\Services;

class ChannelFormattingService
{
    /**
     * Apply channel-specific formatting based on locale.
     */
    public function formatContent(string $channel, ?string $content, ?string $locale): ?string
    {
        if (! is_string($content) || $content === '') {
            return $content;
        }

        $normalizedLocale = mb_strtolower((string) $locale);

        if ($channel === 'email' && str_starts_with($normalizedLocale, 'ar')) {
            if (str_contains($content, 'dir="rtl"')) {
                return $content;
            }

            return '<div dir="rtl" style="text-align:right;">'.$content.'</div>';
        }

        return $content;
    }

    /**
     * Return conservative SMS segment count by locale.
     */
    public function smsSegments(string $body, ?string $locale): int
    {
        $body = trim($body);

        if ($body === '') {
            return 0;
        }

        $unicode = $this->containsUnicode($body) || str_starts_with(mb_strtolower((string) $locale), 'ar');
        $perSegment = $unicode ? 70 : 160;
        $concatSegment = $unicode ? 67 : 153;
        $length = mb_strlen($body);

        if ($length <= $perSegment) {
            return 1;
        }

        return (int) ceil($length / $concatSegment);
    }

    private function containsUnicode(string $value): bool
    {
        return preg_match('/[^\x00-\x7F]/', $value) === 1;
    }
}

