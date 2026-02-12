<?php

namespace Tests\Unit;

use App\Services\VariableRenderingService;
use PHPUnit\Framework\TestCase;

class VariableRenderingServiceTest extends TestCase
{
    public function test_render_string_replaces_known_tokens_and_drops_unknowns(): void
    {
        $service = new VariableRenderingService();

        $rendered = $service->renderString(
            'Hello {{first_name}} from {{company}} {{missing}}',
            [
                'first_name' => 'Ahamed',
                'company' => 'Marketion',
            ]
        );

        $this->assertSame('Hello Ahamed from Marketion ', $rendered);
    }

    public function test_render_array_recursively_renders_nested_values(): void
    {
        $service = new VariableRenderingService();

        $payload = [
            'title' => 'Hello {{first_name}}',
            'body' => [
                'line1' => 'Company: {{company}}',
                'line2' => 'City: {{lead.city}}',
            ],
        ];

        $rendered = $service->renderArray($payload, [
            'first_name' => 'Ahamed',
            'company' => 'Marketion',
            'lead' => [
                'city' => 'Riyadh',
            ],
        ]);

        $this->assertSame('Hello Ahamed', $rendered['title']);
        $this->assertSame('Company: Marketion', $rendered['body']['line1']);
        $this->assertSame('City: Riyadh', $rendered['body']['line2']);
    }
}
