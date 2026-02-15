<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\ScimAccessToken;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ScimProvisioningService
{
    /**
     * Resolve active SCIM token by plaintext token.
     */
    public function token(string $plainText): ?ScimAccessToken
    {
        if (trim($plainText) === '') {
            return null;
        }

        return ScimAccessToken::query()
            ->withoutTenancy()
            ->active()
            ->where('token_hash', hash('sha256', $plainText))
            ->first();
    }

    /**
     * Create or update SCIM user inside tenant.
     *
     * @param array<string, mixed> $payload
     */
    public function provisionUser(Tenant $tenant, array $payload): User
    {
        $email = trim((string) ($payload['userName'] ?? data_get($payload, 'emails.0.value')));

        if ($email === '') {
            abort(422, 'SCIM payload must include userName or emails[0].value.');
        }

        $name = trim((string) (
            data_get($payload, 'name.formatted')
            ?: data_get($payload, 'displayName')
            ?: Str::before($email, '@')
        ));

        $active = ! array_key_exists('active', $payload) || (bool) $payload['active'];

        $role = (string) data_get($payload, 'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User.role', UserRole::Sales->value);
        $resolvedRole = in_array($role, array_map(static fn (UserRole $row) => $row->value, UserRole::cases()), true)
            ? $role
            : UserRole::Sales->value;

        /** @var User $user */
        $user = User::query()
            ->withoutTenancy()
            ->updateOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'email' => $email,
                ],
                [
                    'name' => $name,
                    'role' => $resolvedRole,
                    'password' => Hash::make(Str::random(24)),
                ]
            );

        if (! $active) {
            $user->tokens()->delete();
        }

        return $user;
    }

    /**
     * Disable one SCIM-managed user.
     */
    public function disableUser(User $user): User
    {
        $user->tokens()->delete();

        return $user;
    }
}

