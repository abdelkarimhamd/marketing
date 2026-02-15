<?php

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\TenantRole;
use App\Support\PermissionMatrix;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TenantRole>
 */
class TenantRoleFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<TenantRole>
     */
    protected $model = TenantRole::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = 'Role '.fake()->unique()->numberBetween(1000, 9999);

        return [
            'tenant_id' => Tenant::factory(),
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => fake()->sentence(),
            'permissions' => app(PermissionMatrix::class)->blankMatrix(),
            'is_system' => false,
            'created_by' => null,
            'updated_by' => null,
        ];
    }
}
