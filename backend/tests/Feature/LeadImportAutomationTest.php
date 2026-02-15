<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadImportSchedule;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LeadImportAutomationTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_preset_mapping_with_update_dedupe_updates_existing_lead(): void
    {
        config()->set('enrichment.email.check_mx', false);

        $tenant = Tenant::factory()->create();
        $admin = User::factory()->tenantAdmin()->create(['tenant_id' => $tenant->id]);

        $existing = Lead::factory()->create([
            'tenant_id' => $tenant->id,
            'email' => 'dup@example.test',
            'first_name' => 'Old',
            'city' => 'Riyadh',
        ]);

        Sanctum::actingAs($admin);

        $preset = $this->postJson('/api/admin/lead-import/presets', [
            'name' => 'CSV Mapping',
            'mapping' => [
                'email' => 'email_address',
                'first_name' => 'fname',
                'city' => 'city_name',
            ],
            'dedupe_policy' => 'update',
            'dedupe_keys' => ['email'],
        ])->assertCreated();

        $presetId = (int) $preset->json('preset.id');

        $this->postJson('/api/admin/leads/import', [
            'mapping_preset_id' => $presetId,
            'leads' => [
                [
                    'email_address' => 'dup@example.test',
                    'fname' => 'New',
                    'city_name' => 'Jeddah',
                ],
            ],
        ])->assertCreated()
            ->assertJsonPath('created_count', 0)
            ->assertJsonPath('updated_count', 1)
            ->assertJsonPath('merged_count', 0)
            ->assertJsonPath('skipped_count', 0)
            ->assertJsonPath('dedupe_policy', 'update');

        $existing->refresh();

        $this->assertSame('New', $existing->first_name);
        $this->assertSame('Jeddah', $existing->city);
    }

    public function test_import_merge_dedupe_policy_preserves_existing_values_and_fills_missing(): void
    {
        config()->set('enrichment.email.check_mx', false);

        $tenant = Tenant::factory()->create();
        $admin = User::factory()->tenantAdmin()->create(['tenant_id' => $tenant->id]);

        $existing = Lead::factory()->create([
            'tenant_id' => $tenant->id,
            'email' => 'merge@example.test',
            'first_name' => 'Existing',
            'company' => null,
            'city' => 'Riyadh',
            'score' => 10,
        ]);

        Sanctum::actingAs($admin);

        $this->postJson('/api/admin/leads/import', [
            'dedupe_policy' => 'merge',
            'dedupe_keys' => ['email'],
            'leads' => [
                [
                    'email' => 'merge@example.test',
                    'first_name' => 'Incoming',
                    'company' => 'Smart Cedra',
                    'city' => 'Dammam',
                    'score' => 75,
                ],
            ],
        ])->assertCreated()
            ->assertJsonPath('created_count', 0)
            ->assertJsonPath('updated_count', 0)
            ->assertJsonPath('merged_count', 1)
            ->assertJsonPath('skipped_count', 0)
            ->assertJsonPath('dedupe_policy', 'merge');

        $existing->refresh();

        $this->assertSame('Existing', $existing->first_name);
        $this->assertSame('Smart Cedra', $existing->company);
        $this->assertSame('Riyadh', $existing->city);
        $this->assertGreaterThanOrEqual(75, (int) $existing->score);
    }

    public function test_scheduled_url_import_can_run_now_and_from_console_command(): void
    {
        config()->set('enrichment.email.check_mx', false);

        $tenant = Tenant::factory()->create();
        $admin = User::factory()->tenantAdmin()->create(['tenant_id' => $tenant->id]);

        Sanctum::actingAs($admin);

        $preset = $this->postJson('/api/admin/lead-import/presets', [
            'name' => 'URL CSV Mapping',
            'mapping' => [
                'email' => 'email_address',
                'first_name' => 'fname',
            ],
            'dedupe_policy' => 'skip',
            'dedupe_keys' => ['email'],
        ])->assertCreated();

        $presetId = (int) $preset->json('preset.id');

        $scheduleCreate = $this->postJson('/api/admin/lead-import/schedules', [
            'name' => 'Nightly URL Import',
            'preset_id' => $presetId,
            'source_type' => 'url',
            'source_config' => [
                'url' => 'https://example.test/import.csv',
                'format' => 'csv',
            ],
            'schedule_cron' => '* * * * *',
            'timezone' => 'UTC',
            'is_active' => true,
        ])->assertCreated();

        $scheduleId = (int) $scheduleCreate->json('schedule.id');

        Http::fake([
            'example.test/import.csv' => Http::response(
                "email_address,fname\nlead-one@example.test,Ahamed\n",
                200,
                ['Content-Type' => 'text/csv']
            ),
        ]);

        $this->postJson('/api/admin/lead-import/schedules/'.$scheduleId.'/run')
            ->assertOk()
            ->assertJsonPath('result.status', 'success')
            ->assertJsonPath('result.created_count', 1);

        $this->assertDatabaseHas('leads', [
            'tenant_id' => $tenant->id,
            'email' => 'lead-one@example.test',
            'first_name' => 'Ahamed',
        ]);

        $secondScheduleCreate = $this->postJson('/api/admin/lead-import/schedules', [
            'name' => 'Command URL Import',
            'preset_id' => $presetId,
            'source_type' => 'url',
            'source_config' => [
                'url' => 'https://example.test/import-command.csv',
                'format' => 'csv',
            ],
            'schedule_cron' => '* * * * *',
            'timezone' => 'UTC',
            'is_active' => true,
        ])->assertCreated();

        $secondScheduleId = (int) $secondScheduleCreate->json('schedule.id');

        LeadImportSchedule::query()
            ->withoutTenancy()
            ->whereKey($secondScheduleId)
            ->update([
                'next_run_at' => now()->subMinute(),
            ]);

        Http::fake([
            'example.test/import-command.csv' => Http::response(
                "email_address,fname\nlead-two@example.test,Nora\n",
                200,
                ['Content-Type' => 'text/csv']
            ),
        ]);

        Artisan::call('imports:run-scheduled');

        $this->assertDatabaseHas('leads', [
            'tenant_id' => $tenant->id,
            'email' => 'lead-two@example.test',
            'first_name' => 'Nora',
        ]);

        $schedule = LeadImportSchedule::query()->withoutTenancy()->findOrFail($secondScheduleId);

        $this->assertSame('success', $schedule->last_status);
        $this->assertNotNull($schedule->next_run_at);
        $this->assertNotNull($schedule->last_run_at);
    }
}
