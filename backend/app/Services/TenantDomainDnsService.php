<?php

namespace App\Services;

use App\Support\DomainHost;

class TenantDomainDnsService
{
    /**
     * Resolve CNAME records for host.
     *
     * @return list<string>
     */
    public function lookupCname(string $host): array
    {
        $normalizedHost = DomainHost::normalize($host);

        if ($normalizedHost === null || DomainHost::isLocalHost($normalizedHost)) {
            return [];
        }

        if (! function_exists('dns_get_record')) {
            return [];
        }

        $records = @dns_get_record($normalizedHost, DNS_CNAME);

        if (! is_array($records)) {
            return [];
        }

        $targets = [];

        foreach ($records as $record) {
            if (! is_array($record)) {
                continue;
            }

            $target = DomainHost::normalize((string) ($record['target'] ?? ''));

            if ($target !== null) {
                $targets[] = $target;
            }
        }

        return array_values(array_unique($targets));
    }
}

