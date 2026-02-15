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

    public function test_render_string_supports_conditional_blocks(): void
    {
        $service = new VariableRenderingService();

        $template = '{{#if city=Riyadh}}Local offer{{else}}Global offer{{/if}}';

        $this->assertSame(
            'Local offer',
            $service->renderString($template, ['city' => 'Riyadh'])
        );

        $this->assertSame(
            'Global offer',
            $service->renderString($template, ['city' => 'Jeddah'])
        );
    }

    public function test_render_string_supports_localization_blocks_using_lead_locale(): void
    {
        $service = new VariableRenderingService();

        $template = '{{#lang ar}}مرحبا {{first_name|عميل}}{{/lang}}{{#lang en}}Hello {{first_name|Customer}}{{/lang}}';

        $this->assertSame(
            'مرحبا عميل',
            $service->renderString($template, ['first_name' => '', 'lead' => ['locale' => 'ar_SA']])
        );

        $this->assertSame(
            'Hello Customer',
            $service->renderString($template, ['first_name' => null, 'lead' => ['locale' => 'en_US']])
        );
    }

    public function test_render_string_with_meta_tracks_fallback_and_missing_tokens(): void
    {
        $service = new VariableRenderingService();

        $result = $service->renderStringWithMeta(
            'Hi {{first_name|Customer}} from {{company}}',
            ['first_name' => null]
        );

        $this->assertSame('Hi Customer from ', $result['rendered']);
        $this->assertSame('first_name', $result['meta']['fallbacks_used'][0]['key'] ?? null);
        $this->assertSame('Customer', $result['meta']['fallbacks_used'][0]['fallback'] ?? null);
        $this->assertContains('company', $result['meta']['missing_variables']);
    }

    public function test_render_array_with_meta_aggregates_all_nested_meta_counts(): void
    {
        $service = new VariableRenderingService();

        $result = $service->renderArrayWithMeta(
            [
                'subject' => '{{#if city=Riyadh}}Hi{{else}}Hello{{/if}} {{first_name|Customer}}',
                'body' => [
                    'localized' => '{{#lang ar}}مرحبا{{/lang}}{{#lang en}}Hello{{/lang}}',
                ],
            ],
            [
                'city' => 'Jeddah',
                'first_name' => null,
                'lead' => ['locale' => 'en'],
            ]
        );

        $this->assertSame('Hello Customer', $result['rendered']['subject']);
        $this->assertSame('Hello', $result['rendered']['body']['localized']);
        $this->assertSame(1, $result['meta']['conditions']['evaluated']);
        $this->assertSame(2, $result['meta']['localization']['evaluated']);
        $this->assertNotEmpty($result['meta']['fallbacks_used']);
    }
}
