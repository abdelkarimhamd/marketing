<?php

namespace App\Support;

class DomainHost
{
    /**
     * Normalize host/domain value.
     */
    public static function normalize(?string $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = strtolower(trim($value));

        if ($value === '') {
            return null;
        }

        $parsedHost = parse_url($value, PHP_URL_HOST);

        if (is_string($parsedHost) && $parsedHost !== '') {
            $value = strtolower(trim($parsedHost));
        }

        $value = preg_replace('/:\d+$/', '', $value) ?? $value;
        $value = rtrim($value, '.');

        if ($value === '' || ! preg_match('/^[a-z0-9.-]+$/', $value)) {
            return null;
        }

        return $value;
    }

    /**
     * Determine whether host should be ignored for public mapping.
     */
    public static function isLocalHost(string $host): bool
    {
        return in_array($host, ['localhost', '127.0.0.1'], true);
    }
}

