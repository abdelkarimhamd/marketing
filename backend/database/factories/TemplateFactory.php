<?php

namespace Database\Factories;

use App\Models\Template;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Template>
 */
class TemplateFactory extends Factory
{
    /**
     * The name of the factory's corresponding model.
     *
     * @var class-string<Template>
     */
    protected $model = Template::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = 'Template '.fake()->unique()->numberBetween(1000, 9999);

        return [
            'tenant_id' => Tenant::factory(),
            'brand_id' => null,
            'name' => $name,
            'slug' => Str::slug($name),
            'channel' => 'email',
            'subject' => 'Hello {{first_name}}',
            'content' => '<p>Hi {{first_name}}, welcome to {{company}}.</p>',
            'body_text' => null,
            'whatsapp_template_name' => null,
            'whatsapp_variables' => null,
            'settings' => [],
            'is_active' => true,
        ];
    }

    /**
     * State for SMS templates.
     */
    public function sms(): static
    {
        return $this->state(fn (): array => [
            'channel' => 'sms',
            'subject' => null,
            'content' => 'Hi {{first_name}}, your service is ready.',
            'body_text' => 'Hi {{first_name}}, your service is ready.',
            'whatsapp_template_name' => null,
            'whatsapp_variables' => null,
        ]);
    }

    /**
     * State for WhatsApp templates.
     */
    public function whatsapp(): static
    {
        return $this->state(fn (): array => [
            'channel' => 'whatsapp',
            'subject' => null,
            'content' => '',
            'body_text' => null,
            'whatsapp_template_name' => 'welcome_template',
            'whatsapp_variables' => [
                'name' => '{{first_name}}',
                'company' => '{{company}}',
            ],
        ]);
    }
}
