<?php

namespace Database\Factories;

use App\Models\Lead;
use App\Models\Tenant;
use App\Models\TrackingVisitor;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TrackingVisitor>
 */
class TrackingVisitorFactory extends Factory
{
    protected $model = TrackingVisitor::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'visitor_id' => (string) Str::uuid(),
            'session_id' => (string) Str::uuid(),
            'lead_id' => null,
            'email_hash' => null,
            'phone_hash' => null,
            'first_url' => fake()->url(),
            'last_url' => fake()->url(),
            'referrer' => fake()->url(),
            'utm_json' => ['source' => 'google', 'medium' => 'cpc', 'campaign' => 'demo'],
            'traits_json' => [],
            'first_ip' => fake()->ipv4(),
            'last_ip' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
            'first_seen_at' => now()->subHour(),
            'last_seen_at' => now(),
        ];
    }

    public function forLead(?Lead $lead = null): static
    {
        return $this->state(fn () => [
            'lead_id' => $lead?->id ?? Lead::factory(),
        ]);
    }
}
