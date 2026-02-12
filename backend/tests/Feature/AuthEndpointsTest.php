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

class AuthEndpointsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['api', 'auth:sanctum'])->get('/api/_auth-test/context', function (Request $request) {
            $context = app(TenantContext::class);

            return response()->json([
                'tenant_id' => $context->tenantId(),
                'visible_users' => User::query()->count(),
            ]);
        });
    }

    public function test_login_returns_sanctum_token_and_user_payload(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'role' => UserRole::TenantAdmin->value,
            'email' => 'tenant.admin@example.test',
            'password' => 'password',
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'device_name' => 'phpunit',
        ]);

        $response->assertOk()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('user.email', $user->email)
            ->assertJsonPath('user.role', UserRole::TenantAdmin->value);

        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_me_requires_authentication(): void
    {
        $this->getJson('/api/auth/me')
            ->assertUnauthorized();
    }

    public function test_me_returns_authenticated_user(): void
    {
        $user = User::factory()->tenantAdmin()->create();
        $token = $user->createToken('phpunit')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.role', UserRole::TenantAdmin->value);
    }

    public function test_logout_revokes_current_access_token(): void
    {
        $user = User::factory()->sales()->create();
        $token = $user->createToken('phpunit')->plainTextToken;

        $this->assertDatabaseCount('personal_access_tokens', 1);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/auth/logout')
            ->assertOk()
            ->assertJsonPath('message', 'Logged out successfully.');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_sanctum_authenticated_request_keeps_tenant_scope_active(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $user = User::factory()->tenantAdmin()->create(['tenant_id' => $tenantA->id]);

        User::factory()->count(2)->sales()->create(['tenant_id' => $tenantA->id]);
        User::factory()->count(3)->sales()->create(['tenant_id' => $tenantB->id]);

        $token = $user->createToken('phpunit')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/_auth-test/context')
            ->assertOk()
            ->assertJsonPath('tenant_id', $tenantA->id)
            ->assertJsonPath('visible_users', 3);
    }
}
