<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\ApiKey;
use App\Models\AssignmentRule;
use App\Models\Team;
use App\Models\TeamUser;
use App\Models\Tenant;
use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $demoTenant = Tenant::query()->updateOrCreate(
            ['slug' => 'demo-tenant'],
            [
                'name' => 'Demo Tenant',
                'domain' => 'demo.localhost',
                'is_active' => true,
            ],
        );

        User::query()->updateOrCreate([
            'email' => 'super.admin@demo.test',
        ], [
            'tenant_id' => null,
            'name' => 'Super Admin',
            'role' => UserRole::SuperAdmin->value,
            'password' => 'password',
            'is_super_admin' => true,
        ]);

        $tenantAdmin = User::query()->updateOrCreate([
            'email' => 'tenant.admin@demo.test',
        ], [
            'tenant_id' => $demoTenant->id,
            'name' => 'Tenant Admin',
            'role' => UserRole::TenantAdmin->value,
            'password' => 'password',
            'is_super_admin' => false,
        ]);

        User::query()->updateOrCreate([
            'email' => 'sales@demo.test',
        ], [
            'tenant_id' => $demoTenant->id,
            'name' => 'Sales User',
            'role' => UserRole::Sales->value,
            'password' => 'password',
            'is_super_admin' => false,
        ]);

        $salesUser = User::query()->withoutTenancy()->where('email', 'sales@demo.test')->firstOrFail();

        $team = Team::query()->withoutTenancy()->updateOrCreate(
            [
                'tenant_id' => $demoTenant->id,
                'slug' => 'default-sales-team',
            ],
            [
                'name' => 'Default Sales Team',
                'description' => 'Default team for intake auto-assignment.',
                'is_active' => true,
                'settings' => ['timezone' => 'UTC'],
            ]
        );

        TeamUser::query()->withoutTenancy()->updateOrCreate(
            [
                'tenant_id' => $demoTenant->id,
                'team_id' => $team->id,
                'user_id' => $salesUser->id,
            ],
            [
                'role' => 'member',
                'is_primary' => true,
            ]
        );

        AssignmentRule::query()->withoutTenancy()->updateOrCreate(
            [
                'tenant_id' => $demoTenant->id,
                'name' => 'Default Round Robin',
            ],
            [
                'team_id' => $team->id,
                'fallback_owner_id' => $salesUser->id,
                'is_active' => true,
                'priority' => 100,
                'strategy' => AssignmentRule::STRATEGY_ROUND_ROBIN,
                'auto_assign_on_intake' => true,
                'auto_assign_on_import' => true,
                'conditions' => [],
                'settings' => [],
            ]
        );

        ApiKey::query()->withoutTenancy()->updateOrCreate([
            'key_hash' => hash('sha256', 'demo-public-key'),
        ], [
            'tenant_id' => $demoTenant->id,
            'created_by' => $tenantAdmin->id,
            'name' => 'Demo Public Lead Intake',
            'prefix' => 'demo-public-key',
            'secret' => 'demo-public-key',
            'abilities' => ['public:leads:write'],
            'settings' => [
                'channel' => 'public_intake',
            ],
            'revoked_at' => null,
            'expires_at' => null,
        ]);
    }
}
