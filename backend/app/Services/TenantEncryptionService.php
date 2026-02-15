<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Tenant;
use App\Models\TenantEncryptionKey;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TenantEncryptionService
{
    public const PROVIDER_LOCAL = 'local';

    /**
     * Encrypt one plaintext value with active tenant key.
     */
    public function encryptForTenant(Tenant|int $tenant, string $plaintext): string
    {
        $tenantId = $this->resolveTenantId($tenant);
        $activeKey = $this->activeKey($tenantId, createIfMissing: true);

        if (! $activeKey instanceof TenantEncryptionKey) {
            abort(422, 'Unable to initialize tenant encryption key.');
        }

        return $this->encryptWithKey($activeKey, $plaintext);
    }

    /**
     * Decrypt one tenant ciphertext.
     * Falls back to legacy app-level Crypt payloads.
     */
    public function decryptForTenant(Tenant|int $tenant, ?string $ciphertext): ?string
    {
        if (! is_string($ciphertext) || trim($ciphertext) === '') {
            return null;
        }

        $tenantId = $this->resolveTenantId($tenant);
        $parsed = $this->parseTenantToken($ciphertext);

        if ($parsed === null) {
            return $this->decryptLegacy($ciphertext);
        }

        $key = TenantEncryptionKey::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->where('key_version', $parsed['key_version'])
            ->first();

        if (! $key instanceof TenantEncryptionKey) {
            return null;
        }

        try {
            return $this->encrypterForKey($key)->decryptString($parsed['payload']);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Rotate one tenant encryption key and re-encrypt known secret paths.
     *
     * @return array<string, mixed>
     */
    public function rotateKey(Tenant|int $tenant, ?int $actorUserId = null, ?string $reason = null): array
    {
        $tenantId = $this->resolveTenantId($tenant);

        return DB::transaction(function () use ($tenantId, $actorUserId, $reason): array {
            $tenantModel = Tenant::query()
                ->whereKey($tenantId)
                ->lockForUpdate()
                ->firstOrFail();

            $currentActive = $this->activeKey($tenantId, createIfMissing: true, forUpdate: true);

            if (! $currentActive instanceof TenantEncryptionKey) {
                abort(422, 'Unable to resolve active tenant encryption key.');
            }

            $nextVersion = (int) $currentActive->key_version + 1;
            $currentActive->forceFill([
                'status' => TenantEncryptionKey::STATUS_RETIRED,
                'retired_at' => now(),
                'rotated_by' => $actorUserId,
            ])->save();

            $newActive = $this->createKeyRecord(
                tenantId: $tenantId,
                version: $nextVersion,
                actorUserId: $actorUserId
            );

            $settings = is_array($tenantModel->settings) ? $tenantModel->settings : [];
            $reencrypted = 0;

            foreach ($this->settingsPathsForRotation() as $path) {
                $value = data_get($settings, $path);

                if (! is_string($value) || trim($value) === '') {
                    continue;
                }

                $plaintext = $this->decryptForTenant($tenantId, $value);

                if (! is_string($plaintext) || $plaintext === '') {
                    continue;
                }

                data_set($settings, $path, $this->encryptWithKey($newActive, $plaintext));
                $reencrypted++;
            }

            if ($reencrypted > 0) {
                $tenantModel->forceFill([
                    'settings' => $settings,
                ])->save();
            }

            Activity::query()->withoutTenancy()->create([
                'tenant_id' => $tenantId,
                'actor_id' => $actorUserId,
                'type' => 'tenant.encryption_key.rotated',
                'subject_type' => Tenant::class,
                'subject_id' => $tenantId,
                'description' => 'Tenant encryption key rotated.',
                'properties' => [
                    'old_version' => (int) $currentActive->key_version,
                    'new_version' => (int) $newActive->key_version,
                    'provider' => (string) $newActive->key_provider,
                    'reencrypted_values' => $reencrypted,
                    'reason' => $this->normalizeNullableString($reason),
                ],
            ]);

            return [
                'tenant_id' => $tenantId,
                'old_key_version' => (int) $currentActive->key_version,
                'new_key_version' => (int) $newActive->key_version,
                'provider' => (string) $newActive->key_provider,
                're_encrypted_values' => $reencrypted,
            ];
        });
    }

    /**
     * Return current encryption metadata for one tenant.
     *
     * @return array<string, mixed>
     */
    public function metadataForTenant(Tenant|int $tenant): array
    {
        $tenantId = $this->resolveTenantId($tenant);
        $active = $this->activeKey($tenantId, createIfMissing: false);
        $provider = $active?->key_provider ?? $this->provider();

        return [
            'provider' => $provider,
            'kms_key_id' => $active?->key_reference,
            'active_key_version' => $active?->key_version,
            'active_key_rotated_at' => $active?->activated_at?->toIso8601String(),
            'supports_rotation' => $this->supportsRotation($provider),
            'managed_by' => $provider === self::PROVIDER_LOCAL ? 'application' : 'kms',
        ];
    }

    /**
     * Resolve active key for tenant.
     */
    private function activeKey(int $tenantId, bool $createIfMissing, bool $forUpdate = false): ?TenantEncryptionKey
    {
        $query = TenantEncryptionKey::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->where('status', TenantEncryptionKey::STATUS_ACTIVE)
            ->orderByDesc('key_version');

        if ($forUpdate) {
            $query->lockForUpdate();
        }

        $active = $query->first();

        if ($active instanceof TenantEncryptionKey || ! $createIfMissing) {
            return $active;
        }

        $latestVersion = (int) TenantEncryptionKey::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->max('key_version');

        return $this->createKeyRecord(
            tenantId: $tenantId,
            version: max(1, $latestVersion + 1),
            actorUserId: null
        );
    }

    private function createKeyRecord(int $tenantId, int $version, ?int $actorUserId): TenantEncryptionKey
    {
        $provider = $this->provider();

        if ($provider !== self::PROVIDER_LOCAL) {
            abort(422, 'Unsupported tenant encryption provider. Configure TENANT_ENCRYPTION_PROVIDER=local.');
        }

        $rawKey = random_bytes($this->keyBytes());
        $wrappedKey = Crypt::encryptString(base64_encode($rawKey));

        return TenantEncryptionKey::query()
            ->withoutTenancy()
            ->create([
                'tenant_id' => $tenantId,
                'key_version' => $version,
                'key_provider' => $provider,
                'key_reference' => is_string(config('tenant_encryption.kms_key_id'))
                    ? trim((string) config('tenant_encryption.kms_key_id')) ?: null
                    : null,
                'wrapped_key' => $wrappedKey,
                'status' => TenantEncryptionKey::STATUS_ACTIVE,
                'activated_at' => now(),
                'rotated_by' => $actorUserId,
            ]);
    }

    private function encryptWithKey(TenantEncryptionKey $key, string $plaintext): string
    {
        $encrypted = $this->encrypterForKey($key)->encryptString($plaintext);

        return $this->tokenPrefix().':'.$key->key_version.':'.$encrypted;
    }

    private function encrypterForKey(TenantEncryptionKey $key): Encrypter
    {
        $rawKey = $this->rawKeyFromRecord($key);

        return new Encrypter($rawKey, $this->cipher());
    }

    private function rawKeyFromRecord(TenantEncryptionKey $key): string
    {
        if ($key->key_provider !== self::PROVIDER_LOCAL) {
            abort(422, 'Only local tenant encryption provider is currently supported.');
        }

        if (! is_string($key->wrapped_key) || trim($key->wrapped_key) === '') {
            abort(422, 'Tenant encryption key material is missing.');
        }

        try {
            $decoded = Crypt::decryptString($key->wrapped_key);
        } catch (\Throwable) {
            abort(422, 'Tenant encryption key material cannot be decrypted.');
        }

        $raw = base64_decode($decoded, true);

        if (! is_string($raw) || strlen($raw) !== $this->keyBytes()) {
            abort(422, 'Tenant encryption key material is invalid.');
        }

        return $raw;
    }

    /**
     * @return array{key_version: int, payload: string}|null
     */
    private function parseTenantToken(string $ciphertext): ?array
    {
        $trimmed = trim($ciphertext);
        $prefix = $this->tokenPrefix().':';

        if (! Str::startsWith($trimmed, $prefix)) {
            return null;
        }

        $remainder = substr($trimmed, strlen($prefix));
        $separator = strpos($remainder, ':');

        if (! is_int($separator) || $separator <= 0) {
            return null;
        }

        $versionRaw = substr($remainder, 0, $separator);
        $payload = substr($remainder, $separator + 1);

        if (! is_numeric($versionRaw) || (int) $versionRaw <= 0 || trim($payload) === '') {
            return null;
        }

        return [
            'key_version' => (int) $versionRaw,
            'payload' => $payload,
        ];
    }

    private function decryptLegacy(string $ciphertext): ?string
    {
        try {
            $value = Crypt::decryptString(trim($ciphertext));
        } catch (\Throwable) {
            return null;
        }

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /**
     * @return list<string>
     */
    private function settingsPathsForRotation(): array
    {
        $paths = config('tenant_encryption.settings_reencrypt_paths', []);

        if (! is_array($paths)) {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $path): string => is_string($path) ? trim($path) : '',
            $paths
        )));
    }

    private function resolveTenantId(Tenant|int $tenant): int
    {
        if ($tenant instanceof Tenant) {
            return (int) $tenant->id;
        }

        if ($tenant > 0) {
            return $tenant;
        }

        abort(422, 'Invalid tenant identifier for encryption operation.');
    }

    private function provider(): string
    {
        $provider = mb_strtolower(trim((string) config('tenant_encryption.provider', self::PROVIDER_LOCAL)));

        return $provider !== '' ? $provider : self::PROVIDER_LOCAL;
    }

    private function cipher(): string
    {
        return (string) config('tenant_encryption.cipher', 'AES-256-CBC');
    }

    private function keyBytes(): int
    {
        $bytes = (int) config('tenant_encryption.key_bytes', 32);

        return max(16, min(64, $bytes));
    }

    private function tokenPrefix(): string
    {
        $prefix = trim((string) config('tenant_encryption.token_prefix', 'tenantenc:v1'));

        return $prefix !== '' ? $prefix : 'tenantenc:v1';
    }

    private function supportsRotation(string $provider): bool
    {
        return $provider === self::PROVIDER_LOCAL;
    }

    private function normalizeNullableString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
