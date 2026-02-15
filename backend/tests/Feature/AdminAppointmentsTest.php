<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminAppointmentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_admin_can_book_appointment_for_lead(): void
    {
        $tenant = Tenant::factory()->create();
        $tenantAdmin = User::factory()->tenantAdmin()->create([
            'tenant_id' => $tenant->id,
        ]);
        $owner = User::factory()->sales()->create([
            'tenant_id' => $tenant->id,
        ]);
        $lead = Lead::factory()->create([
            'tenant_id' => $tenant->id,
            'status' => 'new',
            'owner_id' => null,
        ]);

        Sanctum::actingAs($tenantAdmin);

        $startsAt = Carbon::now()->addDay()->startOfHour()->toIso8601String();

        $response = $this->postJson('/api/admin/appointments', [
            'lead_id' => $lead->id,
            'starts_at' => $startsAt,
            'channel' => 'video',
            'owner_id' => $owner->id,
            'deal_stage_on_booking' => 'demo_booked',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('appointment.lead_id', $lead->id)
            ->assertJsonPath('appointment.owner_id', $owner->id)
            ->assertJsonPath('appointment.channel', 'video');

        $appointmentId = (int) $response->json('appointment.id');

        $this->assertDatabaseHas('appointments', [
            'id' => $appointmentId,
            'tenant_id' => $tenant->id,
            'lead_id' => $lead->id,
            'owner_id' => $owner->id,
            'source' => 'admin',
            'channel' => 'video',
        ]);

        $this->assertDatabaseHas('activities', [
            'tenant_id' => $tenant->id,
            'subject_type' => 'App\\Models\\Lead',
            'subject_id' => $lead->id,
            'type' => 'appointment.booked',
        ]);

        $this->assertDatabaseHas('realtime_events', [
            'tenant_id' => $tenant->id,
            'subject_type' => 'App\\Models\\Lead',
            'subject_id' => $lead->id,
            'event_name' => 'appointment.booked',
        ]);

        $lead->refresh();
        $this->assertSame('demo_booked', (string) $lead->status);
        $this->assertSame($owner->id, (int) $lead->owner_id);
    }
}
