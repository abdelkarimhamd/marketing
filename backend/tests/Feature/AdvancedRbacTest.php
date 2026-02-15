<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Tenant;
use App\Models\TenantRole;
use App\Models\User;
use App\Support\PermissionMatrix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdvancedRbacTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_admin_can_create_custom_role(): void
    {
        $tenant = Tenant::factory()->create();
        $tenantAdmin = User::factory()->tenantAdmin()->create([
            'tenant_id' => $tenant->id,
        ]);

        Sanctum::actingAs($tenantAdmin);

        $permissions = $this->matrixWith([
            'leads.view',
            'leads.create',
            'campaigns.view',
        ]);

        $response = $this->postJson('/api/admin/roles', [
            'name' => 'Inside Sales',
            'description' => 'Qualified lead handling role.',
            'permissions' => $permissions,
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('role.name', 'Inside Sales')
            ->assertJsonPath('role.tenant_id', $tenant->id);

        $this->assertDatabaseHas('tenant_roles', [
            'tenant_id' => $tenant->id,
            'name' => 'Inside Sales',
            'is_system' => false,
        ]);
    }

    public function test_user_cannot_create_role_with_permissions_beyond_their_access(): void
    {
        $tenant = Tenant::factory()->create();
        $manager = User::factory()->sales()->create([
            'tenant_id' => $tenant->id,
        ]);

        $actorRole = TenantRole::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Role Builder',
            'slug' => 'role-builder',
            'permissions' => $this->matrixWith(['roles.create']),
        ]);

        $manager->tenantRoles()->attach($actorRole->id, [
            'tenant_id' => $tenant->id,
        ]);

        Sanctum::actingAs($manager);

        $response = $this->postJson('/api/admin/roles', [
            'name' => 'Unsafe Role',
            'permissions' => $this->matrixWith([
                'roles.create',
                'api_keys.delete',
            ]),
        ]);

        $response
            ->assertStatus(422)
            ->assertJsonPath(
                'message',
                "Cannot grant permission 'api_keys.delete' beyond your own access level."
            );

        $this->assertDatabaseMissing('tenant_roles', [
            'tenant_id' => $tenant->id,
            'name' => 'Unsafe Role',
        ]);
    }

    public function test_assigned_custom_role_allows_permitted_endpoints_only(): void
    {
        $tenant = Tenant::factory()->create();
        $sales = User::factory()->sales()->create([
            'tenant_id' => $tenant->id,
        ]);

        $readOnlyLeadRole = TenantRole::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Lead Viewer',
            'slug' => 'lead-viewer',
            'permissions' => $this->matrixWith(['leads.view']),
        ]);

        $sales->tenantRoles()->attach($readOnlyLeadRole->id, [
            'tenant_id' => $tenant->id,
        ]);

        Lead::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        Sanctum::actingAs($sales);

        $this->getJson('/api/admin/leads')
            ->assertOk();

        $this->postJson('/api/admin/leads', [
            'email' => 'new-lead@example.test',
            'first_name' => 'Read',
            'last_name' => 'Only',
        ])->assertForbidden();
    }

    public function test_role_templates_endpoint_returns_expanded_permission_matrices(): void
    {
        $tenant = Tenant::factory()->create();
        $tenantAdmin = User::factory()->tenantAdmin()->create([
            'tenant_id' => $tenant->id,
        ]);

        Sanctum::actingAs($tenantAdmin);

        $this->getJson('/api/admin/roles/templates')
            ->assertOk()
            ->assertJsonPath('templates.sales.permissions.leads.view', true)
            ->assertJsonPath('templates.sales.permissions.playbooks.suggest', true)
            ->assertJsonPath('templates.manager.permissions.campaigns.send', true)
            ->assertJsonPath('templates.admin.permissions.roles.assign', true);
    }

    /**
     * Build a normalized permission matrix from flat permission keys.
     *
     * @param list<string> $permissions
     * @return array<string, array<string, bool>>
     */
    private function matrixWith(array $permissions): array
    {
        $matrix = app(PermissionMatrix::class)->blankMatrix();

        foreach ($permissions as $permission) {
            if (! str_contains($permission, '.')) {
                continue;
            }

            [$resource, $action] = explode('.', $permission, 2);

            if (isset($matrix[$resource][$action])) {
                $matrix[$resource][$action] = true;
            }
        }

        return $matrix;
    }
}
