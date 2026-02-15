<?php

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\TenantDomain;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TenantDomain>
 */
class TenantDomainFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<TenantDomain>
     */
    protected $model = TenantDomain::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'host' => fake()->unique()->domainName(),
            'kind' => TenantDomain::KIND_LANDING,
            'is_primary' => false,
            'cname_target' => config('tenancy.cname_targets.landing', config('tenancy.cname_target')),
            'verification_token' => Str::random(40),
            'verification_status' => TenantDomain::VERIFICATION_PENDING,
            'verified_at' => null,
            'verification_error' => null,
            'ssl_status' => TenantDomain::SSL_PENDING,
            'ssl_provider' => null,
            'ssl_expires_at' => null,
            'ssl_last_checked_at' => null,
            'ssl_error' => null,
            'metadata' => [],
        ];
    }
}

