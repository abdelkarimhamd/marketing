<?php

namespace Database\Factories;

use App\Models\Appointment;
use App\Models\Lead;
use App\Models\Team;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Appointment>
 */
class AppointmentFactory extends Factory
{
    /**
     * @var class-string<Appointment>
     */
    protected $model = Appointment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = Carbon::now()->addDay()->startOfHour();

        return [
            'tenant_id' => Tenant::factory(),
            'lead_id' => Lead::factory(),
            'owner_id' => User::factory(),
            'team_id' => Team::factory(),
            'created_by' => User::factory(),
            'source' => 'portal',
            'channel' => 'video',
            'status' => 'booked',
            'title' => 'Demo Meeting',
            'description' => fake()->sentence(),
            'starts_at' => $start,
            'ends_at' => $start->copy()->addMinutes(30),
            'timezone' => 'UTC',
            'booking_link' => fake()->url(),
            'meeting_url' => fake()->url(),
            'external_refs' => [],
            'meta' => [],
        ];
    }
}
