<?php

namespace App\Services;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LeadEnrichmentService
{
    /**
     * Normalize and enrich incoming lead payload.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function enrich(array $payload): array
    {
        $baseScore = is_numeric($payload['score'] ?? null) ? (int) $payload['score'] : 0;
        $score = $baseScore;

        $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
        $errors = [];

        $countryCode = $this->normalizeCountryCode($payload['country_code'] ?? null);
        $email = $this->normalizeEmail($payload['email'] ?? null);
        $emailDomain = $this->resolveEmailDomain($email);

        $emailIsDisposable = false;
        $emailMxChecked = false;
        $emailMxValid = null;
        $emailValid = false;

        if ($email !== null && $emailDomain !== null) {
            $emailIsDisposable = $this->isDisposableDomain($emailDomain);

            if ($emailIsDisposable) {
                $score -= (int) config('enrichment.score.disposable_email_penalty', 25);

                if ((bool) config('enrichment.email.reject_disposable', false)) {
                    $errors['email'] = 'Disposable email addresses are not allowed.';
                }
            }

            if ($this->shouldCheckMx($emailDomain)) {
                $emailMxChecked = true;
                $emailMxValid = $this->hasMxRecord($emailDomain);

                if ($emailMxValid === false) {
                    $score -= (int) config('enrichment.score.missing_mx_penalty', 5);

                    if ((bool) config('enrichment.email.require_mx', false)) {
                        $errors['email'] = 'Email domain has no MX records.';
                    }
                }
            }

            $emailValid = ! $emailIsDisposable && ($emailMxChecked === false || $emailMxValid === true);

            if ($emailValid) {
                $score += (int) config('enrichment.score.valid_email_bonus', 15);
            }
        }

        $rawPhone = is_string($payload['phone'] ?? null) ? trim((string) $payload['phone']) : '';
        $normalizedPhone = $this->normalizePhone($rawPhone, $countryCode);
        $phoneValid = $normalizedPhone !== null && preg_match('/^\+[1-9]\d{7,14}$/', $normalizedPhone) === 1;
        $phoneCountry = $phoneValid ? $this->inferCountryFromPhone($normalizedPhone) : null;
        $phoneCarrier = $phoneValid ? $this->inferCarrierFromPhone($normalizedPhone, $phoneCountry) : null;

        if ($phoneValid) {
            $score += (int) config('enrichment.score.valid_phone_bonus', 10);
        } elseif ($rawPhone !== '') {
            $score -= (int) config('enrichment.score.invalid_phone_penalty', 12);
        }

        $emailIsMissing = $email === null;
        $requiresValidPhoneWithoutEmail = (bool) config('enrichment.phone.require_valid_without_email', true);

        if ($emailIsMissing && $requiresValidPhoneWithoutEmail && ! $phoneValid) {
            $errors['phone'] = 'A valid phone number is required when email is missing.';
        }

        $company = $this->normalizeNullableString($payload['company'] ?? null);
        $city = $this->normalizeNullableString($payload['city'] ?? null);
        $companyEnriched = false;
        $geoEnriched = false;
        $companySource = null;

        if ($emailDomain !== null) {
            $domainOverride = $this->domainOverride($emailDomain);

            if ($domainOverride !== null) {
                if ($company === null && is_string($domainOverride['name'] ?? null)) {
                    $company = $this->normalizeNullableString($domainOverride['name']);
                    $companyEnriched = $company !== null;
                    $companySource = $companyEnriched ? 'override' : $companySource;
                }

                if ($city === null && is_string($domainOverride['city'] ?? null)) {
                    $city = $this->normalizeNullableString($domainOverride['city']);
                    $geoEnriched = $city !== null || $geoEnriched;
                }

                if ($countryCode === null && is_string($domainOverride['country_code'] ?? null)) {
                    $countryCode = $this->normalizeCountryCode($domainOverride['country_code']);
                    $geoEnriched = $countryCode !== null || $geoEnriched;
                }
            } elseif ((bool) config('enrichment.company.enable_domain_inference', true)) {
                if ($company === null && ! $this->isDisposableDomain($emailDomain) && ! $this->isFreeDomain($emailDomain)) {
                    $company = $this->inferCompanyName($emailDomain);
                    $companyEnriched = $company !== null;
                    $companySource = $companyEnriched ? 'domain' : $companySource;
                }

                if ($countryCode === null) {
                    $countryCode = $this->inferCountryFromDomain($emailDomain);
                    $geoEnriched = $countryCode !== null || $geoEnriched;
                }
            }
        }

        if ($countryCode === null && $phoneCountry !== null) {
            $countryCode = $phoneCountry;
            $geoEnriched = true;
        }

        if ($companyEnriched) {
            $score += (int) config('enrichment.score.company_enriched_bonus', 8);
        }

        if ($geoEnriched) {
            $score += (int) config('enrichment.score.geo_enriched_bonus', 5);
        }

        $clampedScore = $this->clampScore($score);

        $meta['enrichment'] = [
            'version' => 1,
            'processed_at' => now()->toIso8601String(),
            'email' => [
                'normalized' => $email,
                'domain' => $emailDomain,
                'valid' => $emailValid,
                'is_disposable' => $emailIsDisposable,
                'mx_checked' => $emailMxChecked,
                'mx_valid' => $emailMxValid,
            ],
            'phone' => [
                'normalized' => $phoneValid ? $normalizedPhone : null,
                'valid' => $phoneValid,
                'carrier' => $phoneCarrier,
                'country_code' => $phoneCountry,
            ],
            'company' => [
                'value' => $company,
                'source' => $companySource,
            ],
            'geo' => [
                'city' => $city,
                'country_code' => $countryCode,
            ],
            'score' => [
                'before' => $baseScore,
                'after' => $clampedScore,
                'delta' => $clampedScore - $baseScore,
            ],
        ];

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $payload['email'] = $email;
        $payload['phone'] = $phoneValid ? $normalizedPhone : ($rawPhone !== '' ? $rawPhone : null);
        $payload['company'] = $company;
        $payload['city'] = $city;
        $payload['country_code'] = $countryCode;
        $payload['score'] = $clampedScore;
        $payload['meta'] = $meta;

        return $payload;
    }

    private function normalizeEmail(mixed $email): ?string
    {
        if (! is_string($email)) {
            return null;
        }

        $value = mb_strtolower(trim($email));

        return $value !== '' ? $value : null;
    }

    private function resolveEmailDomain(?string $email): ?string
    {
        if (! is_string($email) || $email === '' || ! str_contains($email, '@')) {
            return null;
        }

        $parts = explode('@', $email);
        $domain = mb_strtolower(trim((string) end($parts)));

        if ($domain === '') {
            return null;
        }

        $domain = rtrim($domain, '.');

        if ($domain === '' || ! preg_match('/^[a-z0-9.-]+$/', $domain)) {
            return null;
        }

        return $domain;
    }

    private function normalizePhone(string $phone, ?string $countryCode): ?string
    {
        if ($phone === '') {
            return null;
        }

        $hasPlus = str_starts_with($phone, '+');
        $digits = preg_replace('/\D+/', '', $phone);

        if (! is_string($digits) || $digits === '') {
            return null;
        }

        if ($hasPlus) {
            return '+'.$digits;
        }

        if ($countryCode !== null) {
            $dialingMap = config('enrichment.phone.country_dialing_map', []);
            $dialCode = is_string($dialingMap[$countryCode] ?? null) ? (string) $dialingMap[$countryCode] : '';

            if ($dialCode !== '') {
                if (str_starts_with($digits, $dialCode)) {
                    return '+'.$digits;
                }

                return '+'.$dialCode.ltrim($digits, '0');
            }
        }

        return '+'.$digits;
    }

    private function inferCountryFromPhone(string $phone): ?string
    {
        $digits = ltrim($phone, '+');
        $map = config('enrichment.phone.dialing_country_map', []);

        if (! is_array($map) || $map === []) {
            return null;
        }

        $codes = array_keys($map);
        usort($codes, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        foreach ($codes as $dialCode) {
            if (! str_starts_with($digits, $dialCode)) {
                continue;
            }

            $country = $map[$dialCode] ?? null;

            if (! is_string($country)) {
                return null;
            }

            return mb_strtoupper(trim($country));
        }

        return null;
    }

    private function inferCarrierFromPhone(string $phone, ?string $countryCode): ?string
    {
        if ($countryCode === null) {
            return null;
        }

        $carrierMap = config('enrichment.phone.carrier_prefixes', []);
        $countryPrefixes = $carrierMap[$countryCode] ?? null;

        if (! is_array($countryPrefixes) || $countryPrefixes === []) {
            return null;
        }

        $dialingMap = config('enrichment.phone.country_dialing_map', []);
        $dialCode = is_string($dialingMap[$countryCode] ?? null) ? (string) $dialingMap[$countryCode] : '';
        $digits = ltrim($phone, '+');

        if ($dialCode !== '' && str_starts_with($digits, $dialCode)) {
            $digits = substr($digits, mb_strlen($dialCode)) ?: '';
        }

        $digits = ltrim($digits, '0');

        if ($digits === '') {
            return null;
        }

        $prefixes = array_keys($countryPrefixes);
        usort($prefixes, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        foreach ($prefixes as $prefix) {
            if (str_starts_with($digits, $prefix) && is_string($countryPrefixes[$prefix])) {
                return (string) $countryPrefixes[$prefix];
            }
        }

        return null;
    }

    private function shouldCheckMx(string $domain): bool
    {
        if (! (bool) config('enrichment.email.check_mx', true)) {
            return false;
        }

        if ($this->isLocalDomain($domain)) {
            return false;
        }

        return function_exists('checkdnsrr') || function_exists('dns_get_record');
    }

    private function hasMxRecord(string $domain): bool
    {
        if (function_exists('checkdnsrr') && @checkdnsrr($domain, 'MX')) {
            return true;
        }

        if (! function_exists('dns_get_record')) {
            return false;
        }

        $records = @dns_get_record($domain, DNS_MX);

        return is_array($records) && $records !== [];
    }

    private function inferCompanyName(string $domain): ?string
    {
        $label = $this->registrableLabel($domain);

        if ($label === null) {
            return null;
        }

        $formatted = Str::of($label)
            ->replace(['-', '_'], ' ')
            ->squish()
            ->title()
            ->toString();

        return $formatted !== '' ? $formatted : null;
    }

    private function registrableLabel(string $domain): ?string
    {
        $parts = array_values(array_filter(explode('.', mb_strtolower($domain))));

        if (count($parts) < 2) {
            return null;
        }

        $secondLevelSuffixes = config('enrichment.company.second_level_suffixes', []);
        $lastTwo = $parts[count($parts) - 2].'.'.$parts[count($parts) - 1];

        if (in_array($lastTwo, $secondLevelSuffixes, true) && count($parts) >= 3) {
            return $parts[count($parts) - 3];
        }

        return $parts[count($parts) - 2];
    }

    private function inferCountryFromDomain(string $domain): ?string
    {
        $parts = array_values(array_filter(explode('.', mb_strtolower($domain))));

        if ($parts === []) {
            return null;
        }

        $tld = end($parts);
        $map = config('enrichment.company.country_by_tld', []);
        $country = $map[$tld] ?? null;

        return is_string($country) ? mb_strtoupper(trim($country)) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function domainOverride(string $domain): ?array
    {
        $overrides = config('enrichment.company.domain_overrides', []);

        if (! is_array($overrides)) {
            return null;
        }

        $value = $overrides[mb_strtolower($domain)] ?? null;

        return is_array($value) ? $value : null;
    }

    private function isDisposableDomain(string $domain): bool
    {
        $domains = config('enrichment.email.disposable_domains', []);

        if (! is_array($domains)) {
            return false;
        }

        return in_array(mb_strtolower($domain), array_map('mb_strtolower', $domains), true);
    }

    private function isFreeDomain(string $domain): bool
    {
        $domains = config('enrichment.email.free_domains', []);

        if (! is_array($domains)) {
            return false;
        }

        return in_array(mb_strtolower($domain), array_map('mb_strtolower', $domains), true);
    }

    private function isLocalDomain(string $domain): bool
    {
        if (in_array($domain, ['localhost', '127.0.0.1'], true)) {
            return true;
        }

        $suffixes = config('enrichment.email.local_suffixes', ['.localhost', '.local', '.test']);

        if (! is_array($suffixes)) {
            return false;
        }

        foreach ($suffixes as $suffix) {
            if (! is_string($suffix) || $suffix === '') {
                continue;
            }

            if (str_ends_with($domain, mb_strtolower($suffix))) {
                return true;
            }
        }

        return false;
    }

    private function normalizeCountryCode(mixed $countryCode): ?string
    {
        if (! is_string($countryCode)) {
            return null;
        }

        $value = mb_strtoupper(trim($countryCode));

        return $value !== '' ? $value : null;
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function clampScore(int $score): int
    {
        $min = (int) config('enrichment.score.min', 0);
        $max = (int) config('enrichment.score.max', 100);

        return max($min, min($max, $score));
    }
}
