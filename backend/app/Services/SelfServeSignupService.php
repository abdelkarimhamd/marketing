<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\BillingPlan;
use App\Models\CheckoutSession;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SelfServeSignupService
{
    public function __construct(
        private readonly TenantRoleTemplateService $templateService,
    ) {
    }

    /**
     * Create tenant + first admin + starter subscription.
     *
     * @param array<string, mixed> $payload
     * @return array{tenant: Tenant, user: User, subscription: TenantSubscription|null, checkout_session: CheckoutSession|null}
     */
    public function signup(array $payload): array
    {
        return DB::transaction(function () use ($payload): array {
            $tenantName = trim((string) $payload['tenant_name']);
            $tenantSlug = trim((string) ($payload['tenant_slug'] ?? Str::slug($tenantName)));
            $email = trim((string) $payload['email']);
            $password = (string) $payload['password'];
            $planSlug = trim((string) ($payload['plan_slug'] ?? 'starter'));

            $tenant = Tenant::query()->create([
                'name' => $tenantName,
                'slug' => $tenantSlug !== '' ? $tenantSlug : Str::slug($tenantName.'-'.Str::random(4)),
                'domain' => null,
                'settings' => [
                    'self_serve_signup' => true,
                    'signup_at' => now()->toIso8601String(),
                ],
                'branding' => [
                    'landing_theme' => 'default',
                ],
                'timezone' => 'UTC',
                'locale' => 'en',
                'currency' => 'USD',
                'sso_required' => false,
                'is_active' => true,
            ]);

            $user = User::query()->withoutTenancy()->create([
                'tenant_id' => $tenant->id,
                'name' => trim((string) ($payload['name'] ?? 'Tenant Admin')),
                'email' => $email,
                'role' => UserRole::TenantAdmin->value,
                'password' => $password,
                'is_super_admin' => false,
            ]);

            $templates = $this->templateService->ensureTenantTemplates((int) $tenant->id, (int) $user->id);
            $adminRole = $templates->get('admin');

            if ($adminRole !== null) {
                $user->tenantRoles()->syncWithoutDetaching([
                    $adminRole->id => ['tenant_id' => $tenant->id],
                ]);
            }

            $plan = BillingPlan::query()
                ->where('slug', $planSlug)
                ->where('is_active', true)
                ->first();

            $subscription = null;
            $checkoutSession = null;

            if ($plan !== null) {
                $subscription = TenantSubscription::query()->withoutTenancy()->create([
                    'tenant_id' => $tenant->id,
                    'billing_plan_id' => $plan->id,
                    'status' => 'trialing',
                    'current_period_start' => now(),
                    'current_period_end' => now()->addDays(14),
                    'provider' => 'stripe',
                    'metadata' => ['self_serve' => true],
                ]);

                $checkoutSession = CheckoutSession::query()->withoutTenancy()->create([
                    'tenant_id' => $tenant->id,
                    'billing_plan_id' => $plan->id,
                    'email' => $email,
                    'provider' => 'stripe',
                    'provider_session_id' => null,
                    'status' => 'pending',
                    'coupon_code' => trim((string) ($payload['coupon_code'] ?? '')) ?: null,
                    'payload' => [
                        'plan_slug' => $plan->slug,
                        'proration' => true,
                    ],
                ]);
            }

            return [
                'tenant' => $tenant,
                'user' => $user,
                'subscription' => $subscription,
                'checkout_session' => $checkoutSession,
            ];
        });
    }
}
