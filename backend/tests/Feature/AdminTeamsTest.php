<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminTeamsTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_admin_can_update_team_booking_link(): void
    {
        $tenant = Tenant::factory()->create();
        $tenantAdmin = User::factory()->tenantAdmin()->create([
            'tenant_id' => $tenant->id,
        ]);
        $team = Team::factory()->create([
            'tenant_id' => $tenant->id,
        ]);

        Sanctum::actingAs($tenantAdmin);

        $this->patchJson('/api/admin/teams/'.$team->id.'/booking-link', [
            'booking_link' => 'https://book.example.test/teams/default',
        ])
            ->assertOk()
            ->assertJsonPath('team.booking_link', 'https://book.example.test/teams/default');

        $team->refresh();
        $this->assertSame('https://book.example.test/teams/default', data_get($team->settings, 'booking.link'));

        $this->getJson('/api/admin/teams')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $team->id,
                'booking_link' => 'https://book.example.test/teams/default',
            ]);
    }
}
