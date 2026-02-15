<?php

namespace App\Services;

use App\Models\Tenant;
use Illuminate\Support\Facades\Crypt;

class TenantEmailConfigurationService
{
    public const MODE_PLATFORM = 'platform';
    public const MODE_TENANT = 'tenant';

    public function __construct(
        private readonly TenantEncryptionService $tenantEncryptionService
    ) {
    }

    /**
     * Resolve email provider for tenant with global fallback.
     */
    public function providerForTenant(?int $tenantId): string
    {
        $fallback = mb_strtolower((string) config('messaging.providers.email', 'mock'));

        if (! is_int($tenantId) || $tenantId <= 0) {
            return $fallback;
        }

        $settings = $this->tenantSettings($tenantId);
        $value = data_get($settings, 'providers.email');

        if (! is_string($value) || trim($value) === '') {
            return $fallback;
        }

        return mb_strtolower(trim($value));
    }

    /**
     * Resolve effective "from" address for one tenant.
     */
    public function fromAddressForTenant(int $tenantId): string
    {
        $fallback = (string) config('mail.from.address', 'hello@example.com');
        $delivery = $this->deliverySettings($tenantId);

        if (($delivery['mode'] ?? self::MODE_PLATFORM) !== self::MODE_TENANT) {
            return $fallback;
        }

        $fromAddress = is_string($delivery['from_address'] ?? null)
            ? trim((string) $delivery['from_address'])
            : '';

        return $fromAddress !== '' ? $fromAddress : $fallback;
    }

    /**
     * Resolve effective "from" name for one tenant.
     */
    public function fromNameForTenant(int $tenantId): string
    {
        $fallback = (string) config('mail.from.name', 'Marketion');
        $delivery = $this->deliverySettings($tenantId);

        if (($delivery['mode'] ?? self::MODE_PLATFORM) !== self::MODE_TENANT) {
            return $fallback;
        }

        $fromName = is_string($delivery['from_name'] ?? null)
            ? trim((string) $delivery['from_name'])
            : '';

        return $fromName !== '' ? $fromName : $fallback;
    }

    /**
     * Resolve per-tenant SMTP override configuration.
     *
     * @return array<string, scalar|null>|null
     */
    public function smtpOverridesForTenant(int $tenantId): ?array
    {
        $delivery = $this->deliverySettings($tenantId);

        if (($delivery['mode'] ?? self::MODE_PLATFORM) !== self::MODE_TENANT) {
            return null;
        }

        if (! (bool) ($delivery['use_custom_smtp'] ?? false)) {
            return null;
        }

        $host = is_string($delivery['smtp_host'] ?? null) ? trim((string) $delivery['smtp_host']) : '';
        $port = is_numeric($delivery['smtp_port'] ?? null) ? (int) $delivery['smtp_port'] : 0;
        $username = is_string($delivery['smtp_username'] ?? null) ? trim((string) $delivery['smtp_username']) : '';
        $encryption = is_string($delivery['smtp_encryption'] ?? null)
            ? mb_strtolower(trim((string) $delivery['smtp_encryption']))
            : null;

        $encryptedPassword = is_string($delivery['smtp_password_encrypted'] ?? null)
            ? (string) $delivery['smtp_password_encrypted']
            : null;
        $password = $this->decryptPassword($encryptedPassword, $tenantId);

        if ($host === '' || $port <= 0 || $username === '' || ! is_string($password) || $password === '') {
            return null;
        }

        $fromAddress = $this->fromAddressForTenant($tenantId);
        $fromName = $this->fromNameForTenant($tenantId);

        return [
            'host' => $host,
            'port' => $port,
            'username' => $username,
            'password' => $password,
            'encryption' => in_array($encryption, ['tls', 'ssl'], true) ? $encryption : null,
            'from_address' => $fromAddress,
            'from_name' => $fromName,
        ];
    }

    /**
     * Resolve tenant email-delivery settings with defaults.
     *
     * @return array<string, mixed>
     */
    public function deliverySettings(int $tenantId): array
    {
        $settings = $this->tenantSettings($tenantId);
        $delivery = is_array($settings['email_delivery'] ?? null) ? $settings['email_delivery'] : [];

        $mode = is_string($delivery['mode'] ?? null)
            ? mb_strtolower(trim((string) $delivery['mode']))
            : self::MODE_PLATFORM;

        if (! in_array($mode, [self::MODE_PLATFORM, self::MODE_TENANT], true)) {
            $mode = self::MODE_PLATFORM;
        }

        return [
            'mode' => $mode,
            'from_address' => $delivery['from_address'] ?? null,
            'from_name' => $delivery['from_name'] ?? null,
            'use_custom_smtp' => (bool) ($delivery['use_custom_smtp'] ?? false),
            'smtp_host' => $delivery['smtp_host'] ?? null,
            'smtp_port' => $delivery['smtp_port'] ?? null,
            'smtp_username' => $delivery['smtp_username'] ?? null,
            'smtp_encryption' => $delivery['smtp_encryption'] ?? null,
            'smtp_password_encrypted' => $delivery['smtp_password_encrypted'] ?? null,
        ];
    }

    /**
     * Decrypt stored SMTP password.
     */
    public function decryptPassword(?string $encrypted, ?int $tenantId = null): ?string
    {
        if (! is_string($encrypted) || trim($encrypted) === '') {
            return null;
        }

        if (is_int($tenantId) && $tenantId > 0) {
            return $this->tenantEncryptionService->decryptForTenant($tenantId, $encrypted);
        }

        try {
            $value = Crypt::decryptString($encrypted);
        } catch (\Throwable) {
            return null;
        }

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /**
     * Load tenant settings payload.
     *
     * @return array<string, mixed>
     */
    private function tenantSettings(int $tenantId): array
    {
        $tenant = Tenant::query()->whereKey($tenantId)->first();

        return is_array($tenant?->settings) ? $tenant->settings : [];
    }
}
