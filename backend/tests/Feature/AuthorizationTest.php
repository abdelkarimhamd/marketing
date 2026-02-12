<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_access_gate_matches_roles(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();
        $tenantAdmin = User::factory()->tenantAdmin()->create();
        $sales = User::factory()->sales()->create();

        $this->assertTrue(Gate::forUser($superAdmin)->allows('admin.access'));
        $this->assertTrue(Gate::forUser($tenantAdmin)->allows('admin.access'));
        $this->assertFalse(Gate::forUser($sales)->allows('admin.access'));
    }

    public function test_tenant_access_gate_enforces_isolation(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $superAdmin = User::factory()->superAdmin()->create();
        $tenantAdmin = User::factory()->tenantAdmin()->create([
            'tenant_id' => $tenantA->id,
        ]);

        $this->assertTrue(Gate::forUser($superAdmin)->allows('tenant.access', $tenantA));
        $this->assertTrue(Gate::forUser($tenantAdmin)->allows('tenant.access', $tenantA));
        $this->assertFalse(Gate::forUser($tenantAdmin)->allows('tenant.access', $tenantB));
    }

    public function test_user_policy_respects_tenant_boundaries_and_roles(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $tenantAdmin = User::factory()->tenantAdmin()->create([
            'tenant_id' => $tenantA->id,
        ]);

        $sameTenantUser = User::factory()->sales()->create([
            'tenant_id' => $tenantA->id,
            'role' => UserRole::Sales->value,
        ]);

        $otherTenantUser = User::factory()->sales()->create([
            'tenant_id' => $tenantB->id,
            'role' => UserRole::Sales->value,
        ]);

        $salesUser = User::factory()->sales()->create([
            'tenant_id' => $tenantA->id,
            'role' => UserRole::Sales->value,
        ]);

        $this->assertTrue(Gate::forUser($tenantAdmin)->allows('view', $sameTenantUser));
        $this->assertFalse(Gate::forUser($tenantAdmin)->allows('view', $otherTenantUser));
        $this->assertTrue(Gate::forUser($salesUser)->allows('view', $salesUser));
        $this->assertFalse(Gate::forUser($salesUser)->allows('view', $sameTenantUser));
    }
}
