<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Account;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccountsModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_index_is_tenant_isolated(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $adminA = User::factory()->tenantAdmin()->create(['tenant_id' => $tenantA->id]);

        Account::factory()->create([
            'tenant_id' => $tenantA->id,
            'name' => 'Acme Clinic',
            'owner_user_id' => $adminA->id,
        ]);

        Account::factory()->create([
            'tenant_id' => $tenantB->id,
            'name' => 'Hidden Account',
        ]);

        Sanctum::actingAs($adminA);

        $this->getJson('/api/admin/accounts')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.name', 'Acme Clinic');
    }

    public function test_admin_can_attach_and_detach_contact_from_account(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()->tenantAdmin()->create(['tenant_id' => $tenant->id]);

        $account = Account::factory()->create([
            'tenant_id' => $tenant->id,
            'owner_user_id' => $admin->id,
        ]);

        $lead = Lead::factory()->create([
            'tenant_id' => $tenant->id,
            'email' => 'contact@example.test',
        ]);

        Sanctum::actingAs($admin);

        $this->postJson("/api/admin/accounts/{$account->id}/contacts/attach", [
            'lead_id' => $lead->id,
            'is_primary' => true,
            'job_title' => 'Manager',
        ])->assertOk();

        $this->assertDatabaseHas('account_contacts', [
            'tenant_id' => $tenant->id,
            'account_id' => $account->id,
            'lead_id' => $lead->id,
            'is_primary' => 1,
            'job_title' => 'Manager',
        ]);

        $this->postJson("/api/admin/accounts/{$account->id}/contacts/detach", [
            'lead_id' => $lead->id,
        ])->assertOk();

        $this->assertDatabaseMissing('account_contacts', [
            'tenant_id' => $tenant->id,
            'account_id' => $account->id,
            'lead_id' => $lead->id,
        ]);
    }

    public function test_sales_user_without_roles_cannot_access_accounts_module(): void
    {
        $tenant = Tenant::factory()->create();
        $sales = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => UserRole::Sales->value,
        ]);

        Sanctum::actingAs($sales);

        $this->getJson('/api/admin/accounts')->assertForbidden();
    }
}
