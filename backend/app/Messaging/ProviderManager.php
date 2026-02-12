<?php

namespace App\Messaging;

use App\Messaging\Contracts\EmailProviderInterface;
use App\Messaging\Contracts\SmsProviderInterface;
use App\Messaging\Contracts\WhatsAppProviderInterface;
use App\Messaging\Providers\MetaWhatsAppCloudProvider;
use App\Messaging\Providers\MockEmailProvider;
use App\Messaging\Providers\MockSmsProvider;
use App\Messaging\Providers\MockWhatsAppProvider;
use App\Messaging\Providers\SmtpEmailProvider;
use App\Messaging\Providers\TwilioSmsProvider;
use InvalidArgumentException;

class ProviderManager
{
    /**
     * Resolve configured email provider implementation.
     */
    public function emailProvider(): EmailProviderInterface
    {
        $driver = mb_strtolower((string) config('messaging.providers.email', 'mock'));

        return match ($driver) {
            'mock' => app(MockEmailProvider::class),
            'smtp' => app(SmtpEmailProvider::class),
            default => throw new InvalidArgumentException("Unsupported email provider '{$driver}'."),
        };
    }

    /**
     * Resolve configured SMS provider implementation.
     */
    public function smsProvider(): SmsProviderInterface
    {
        $driver = mb_strtolower((string) config('messaging.providers.sms', 'mock'));

        return match ($driver) {
            'mock' => app(MockSmsProvider::class),
            'twilio' => app(TwilioSmsProvider::class),
            default => throw new InvalidArgumentException("Unsupported SMS provider '{$driver}'."),
        };
    }

    /**
     * Resolve configured WhatsApp provider implementation.
     */
    public function whatsappProvider(): WhatsAppProviderInterface
    {
        $driver = mb_strtolower((string) config('messaging.providers.whatsapp', 'mock'));

        return match ($driver) {
            'mock' => app(MockWhatsAppProvider::class),
            'meta', 'meta_whatsapp' => app(MetaWhatsAppCloudProvider::class),
            default => throw new InvalidArgumentException("Unsupported WhatsApp provider '{$driver}'."),
        };
    }
}
