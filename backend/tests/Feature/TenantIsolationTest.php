<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('web')->get('/_tenant-test/context', function (Request $request) {
            $context = app(TenantContext::class);

            return response()->json([
                'tenant_id' => $context->tenantId(),
                'tenant_bypassed' => $context->isBypassed(),
                'visible_users' => User::query()->count(),
            ]);
        });
    }

    public function test_non_super_admin_is_scoped_to_its_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $user = User::factory()->create([
            'tenant_id' => $tenantA->id,
            'role' => UserRole::Sales->value,
        ]);

        User::factory()->count(2)->create(['tenant_id' => $tenantA->id]);
        User::factory()->count(3)->create(['tenant_id' => $tenantB->id]);

        $this->actingAs($user)
            ->getJson('/_tenant-test/context')
            ->assertOk()
            ->assertJson([
                'tenant_id' => $tenantA->id,
                'tenant_bypassed' => false,
                'visible_users' => 3,
            ]);
    }

    public function test_non_super_admin_cannot_switch_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $user = User::factory()->create([
            'tenant_id' => $tenantA->id,
            'role' => UserRole::Sales->value,
        ]);

        $this->actingAs($user)
            ->getJson('/_tenant-test/context?tenant_id='.$tenantB->id)
            ->assertForbidden();
    }

    public function test_super_admin_can_bypass_and_switch_tenants(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        User::factory()->count(2)->create(['tenant_id' => $tenantA->id]);
        User::factory()->create(['tenant_id' => $tenantB->id]);

        $superAdmin = User::factory()->superAdmin()->create();

        $this->actingAs($superAdmin)
            ->getJson('/_tenant-test/context')
            ->assertOk()
            ->assertJson([
                'tenant_id' => null,
                'tenant_bypassed' => true,
                'visible_users' => 4,
            ]);

        $this->getJson('/_tenant-test/context?tenant_id='.$tenantA->id)
            ->assertOk()
            ->assertJson([
                'tenant_id' => $tenantA->id,
                'tenant_bypassed' => false,
                'visible_users' => 2,
            ]);

        $this->getJson('/_tenant-test/context')
            ->assertOk()
            ->assertJson([
                'tenant_id' => $tenantA->id,
                'tenant_bypassed' => false,
                'visible_users' => 2,
            ]);

        $this->getJson('/_tenant-test/context?tenant_id=all')
            ->assertOk()
            ->assertJson([
                'tenant_id' => null,
                'tenant_bypassed' => true,
                'visible_users' => 4,
            ]);
    }
}
