<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\TenantRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminUsersTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_create_tenant_user_with_template_role(): void
    {
        $tenant = Tenant::factory()->create();
        $superAdmin = User::factory()->superAdmin()->create();

        Sanctum::actingAs($superAdmin);

        $response = $this
            ->withHeader('X-Tenant-ID', (string) $tenant->id)
            ->postJson('/api/admin/users', [
                'name' => 'Manager One',
                'email' => 'manager.one@example.test',
                'password' => 'password123',
                'template_key' => 'manager',
            ]);

        $response
            ->assertCreated()
            ->assertJsonPath('user.tenant_id', $tenant->id)
            ->assertJsonPath('user.email', 'manager.one@example.test')
            ->assertJsonPath('user.role', UserRole::Sales->value);

        $createdUserId = (int) $response->json('user.id');
        $managerRoleId = TenantRole::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenant->id)
            ->where('slug', 'template-manager')
            ->value('id');

        $this->assertNotNull($managerRoleId);

        $this->assertDatabaseHas('tenant_role_user', [
            'tenant_id' => $tenant->id,
            'user_id' => $createdUserId,
            'tenant_role_id' => $managerRoleId,
        ]);
    }

    public function test_tenant_admin_can_create_tenant_admin_with_default_admin_template(): void
    {
        $tenant = Tenant::factory()->create();
        $tenantAdmin = User::factory()->tenantAdmin()->create([
            'tenant_id' => $tenant->id,
        ]);

        Sanctum::actingAs($tenantAdmin);

        $response = $this->postJson('/api/admin/users', [
            'name' => 'Team Admin',
            'email' => 'team.admin@example.test',
            'password' => 'password123',
            'role' => UserRole::TenantAdmin->value,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('user.tenant_id', $tenant->id)
            ->assertJsonPath('user.role', UserRole::TenantAdmin->value);

        $createdUserId = (int) $response->json('user.id');
        $adminTemplateRoleId = TenantRole::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenant->id)
            ->where('slug', 'template-admin')
            ->value('id');

        $this->assertNotNull($adminTemplateRoleId);

        $this->assertDatabaseHas('tenant_role_user', [
            'tenant_id' => $tenant->id,
            'user_id' => $createdUserId,
            'tenant_role_id' => $adminTemplateRoleId,
        ]);
    }

    public function test_sales_user_cannot_create_tenant_users(): void
    {
        $tenant = Tenant::factory()->create();
        $sales = User::factory()->sales()->create([
            'tenant_id' => $tenant->id,
        ]);

        Sanctum::actingAs($sales);

        $this->postJson('/api/admin/users', [
            'name' => 'Blocked User',
            'email' => 'blocked.user@example.test',
            'password' => 'password123',
        ])->assertForbidden();
    }

    public function test_super_admin_must_select_tenant_context_before_creating_users(): void
    {
        $superAdmin = User::factory()->superAdmin()->create();

        Sanctum::actingAs($superAdmin);

        $this->postJson('/api/admin/users', [
            'name' => 'No Tenant',
            'email' => 'no.tenant@example.test',
            'password' => 'password123',
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Tenant context is required.');
    }

    public function test_tenant_admin_can_update_user_availability_profile(): void
    {
        $tenant = Tenant::factory()->create();
        $tenantAdmin = User::factory()->tenantAdmin()->create([
            'tenant_id' => $tenant->id,
        ]);
        $sales = User::factory()->sales()->create([
            'tenant_id' => $tenant->id,
        ]);

        Sanctum::actingAs($tenantAdmin);

        $this->patchJson('/api/admin/users/'.$sales->id.'/availability', [
            'availability' => [
                'timezone' => 'Asia/Riyadh',
                'status' => 'available',
                'is_online' => true,
                'max_active_leads' => 40,
                'working_hours' => [
                    'days' => [1, 2, 3, 4, 5],
                    'start' => '09:00',
                    'end' => '17:00',
                ],
                'holidays' => [
                    now()->addDays(1)->toDateString(),
                ],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('user.availability.timezone', 'Asia/Riyadh')
            ->assertJsonPath('user.availability.max_active_leads', 40);

        $sales->refresh();

        $this->assertSame('Asia/Riyadh', data_get($sales->settings, 'assignment.availability.timezone'));
        $this->assertSame(40, data_get($sales->settings, 'assignment.availability.max_active_leads'));

        $this->getJson('/api/admin/users')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $sales->id,
                'timezone' => 'Asia/Riyadh',
                'max_active_leads' => 40,
            ]);
    }

    public function test_tenant_admin_can_update_user_booking_link(): void
    {
        $tenant = Tenant::factory()->create();
        $tenantAdmin = User::factory()->tenantAdmin()->create([
            'tenant_id' => $tenant->id,
        ]);
        $sales = User::factory()->sales()->create([
            'tenant_id' => $tenant->id,
        ]);

        Sanctum::actingAs($tenantAdmin);

        $this->patchJson('/api/admin/users/'.$sales->id.'/booking-link', [
            'booking_link' => 'https://book.example.test/agents/sales',
        ])
            ->assertOk()
            ->assertJsonPath('user.booking_link', 'https://book.example.test/agents/sales');

        $sales->refresh();

        $this->assertSame('https://book.example.test/agents/sales', data_get($sales->settings, 'booking.link'));

        $this->getJson('/api/admin/users')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $sales->id,
                'booking_link' => 'https://book.example.test/agents/sales',
            ]);
    }
}
