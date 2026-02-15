<?php

namespace Tests\Unit;

use App\Messaging\DTO\OutgoingMessageData;
use App\Messaging\Providers\MetaWhatsAppCloudProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MetaWhatsAppCloudProviderTest extends TestCase
{
    public function test_send_builds_image_payload_from_media_meta(): void
    {
        config()->set('messaging.meta_whatsapp.token', 'meta-token');
        config()->set('messaging.meta_whatsapp.phone_number_id', '1234567890');
        config()->set('messaging.meta_whatsapp.version', 'v20.0');

        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'messages' => [
                    ['id' => 'wamid.media.123'],
                ],
            ], 200),
        ]);

        $provider = new MetaWhatsAppCloudProvider();
        $result = $provider->send(new OutgoingMessageData(
            messageId: 1,
            tenantId: 1,
            channel: 'whatsapp',
            to: '15550001111',
            from: null,
            subject: null,
            body: null,
            provider: 'meta',
            meta: [
                'message_type' => 'image',
                'media' => [
                    'link' => 'https://cdn.example.test/banner.png',
                    'caption' => 'Preview image',
                ],
            ],
        ));

        $this->assertTrue($result->accepted);
        $this->assertSame('wamid.media.123', $result->providerMessageId);

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return $request->url() === 'https://graph.facebook.com/v20.0/1234567890/messages'
                && $request->method() === 'POST'
                && data_get($data, 'type') === 'image'
                && data_get($data, 'image.link') === 'https://cdn.example.test/banner.png'
                && data_get($data, 'image.caption') === 'Preview image';
        });
    }

    public function test_send_builds_catalog_list_payload(): void
    {
        config()->set('messaging.meta_whatsapp.token', 'meta-token');
        config()->set('messaging.meta_whatsapp.phone_number_id', '1234567890');
        config()->set('messaging.meta_whatsapp.version', 'v20.0');

        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'messages' => [
                    ['id' => 'wamid.catalog.123'],
                ],
            ], 200),
        ]);

        $provider = new MetaWhatsAppCloudProvider();
        $result = $provider->send(new OutgoingMessageData(
            messageId: 2,
            tenantId: 1,
            channel: 'whatsapp',
            to: '15550002222',
            from: null,
            subject: null,
            body: 'Choose a package',
            provider: 'meta',
            meta: [
                'message_type' => 'catalog_list',
                'catalog' => [
                    'catalog_id' => 'CAT-1',
                    'sections' => [
                        [
                            'title' => 'Plans',
                            'items' => ['plan-basic', 'plan-pro'],
                        ],
                    ],
                ],
            ],
        ));

        $this->assertTrue($result->accepted);
        $this->assertSame('wamid.catalog.123', $result->providerMessageId);

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return data_get($data, 'type') === 'interactive'
                && data_get($data, 'interactive.type') === 'product_list'
                && data_get($data, 'interactive.action.catalog_id') === 'CAT-1'
                && data_get($data, 'interactive.action.sections.0.product_items.0.product_retailer_id') === 'plan-basic'
                && data_get($data, 'interactive.action.sections.0.product_items.1.product_retailer_id') === 'plan-pro';
        });
    }
}

