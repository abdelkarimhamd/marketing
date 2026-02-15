<?php

namespace Database\Factories;

use App\Models\Brand;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Brand>
 */
class BrandFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<Brand>
     */
    protected $model = Brand::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company().' Brand';
        $slug = Str::slug($name);

        return [
            'tenant_id' => Tenant::factory(),
            'name' => $name,
            'slug' => $slug,
            'is_active' => true,
            'email_from_address' => 'noreply+'.Str::lower($slug).'@example.test',
            'email_from_name' => fake()->company(),
            'email_reply_to' => 'support+'.Str::lower($slug).'@example.test',
            'sms_sender_id' => Str::upper(Str::substr($slug, 0, 11)),
            'whatsapp_phone_number_id' => 'wa-'.fake()->unique()->numerify('########'),
            'landing_domain' => $slug.'.example.test',
            'landing_page' => [
                'headline' => fake()->sentence(4),
                'subtitle' => fake()->sentence(10),
            ],
            'branding' => [
                'landing_theme' => fake()->randomElement(['default', 'modern', 'enterprise']),
            ],
            'signatures' => [
                'email_html' => '<p>Regards,<br>'.$name.'</p>',
                'sms' => '-- '.$name,
                'whatsapp' => $name,
            ],
            'settings' => [],
        ];
    }
}
