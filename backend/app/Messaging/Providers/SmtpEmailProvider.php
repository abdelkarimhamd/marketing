<?php

namespace App\Messaging\Providers;

use App\Messaging\Contracts\EmailProviderInterface;
use App\Messaging\DTO\OutgoingMessageData;
use App\Messaging\DTO\ProviderSendResult;
use App\Services\TenantEmailConfigurationService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

class SmtpEmailProvider implements EmailProviderInterface
{
    public function __construct(
        private readonly TenantEmailConfigurationService $tenantEmailConfigurationService,
    ) {
    }

    /**
     * Send email through Laravel's configured SMTP mailer.
     */
    public function send(OutgoingMessageData $message): ProviderSendResult
    {
        $smtpOverrides = $this->tenantEmailConfigurationService->smtpOverridesForTenant((int) $message->tenantId);
        $originalSmtpConfig = config('mail.mailers.smtp');
        $originalFrom = config('mail.from');

        if (is_array($smtpOverrides)) {
            config()->set('mail.mailers.smtp.host', $smtpOverrides['host']);
            config()->set('mail.mailers.smtp.port', $smtpOverrides['port']);
            config()->set('mail.mailers.smtp.username', $smtpOverrides['username']);
            config()->set('mail.mailers.smtp.password', $smtpOverrides['password']);
            config()->set('mail.mailers.smtp.encryption', $smtpOverrides['encryption']);
            config()->set('mail.from.address', $smtpOverrides['from_address']);
            config()->set('mail.from.name', $smtpOverrides['from_name']);
        }

        try {
            $body = (string) ($message->body ?? '');

            if ($body === '') {
                return ProviderSendResult::failed('smtp', 'Email body is empty.');
            }

            Mail::mailer('smtp')->html($body, function ($mail) use ($message): void {
                $mail->to($message->to);

                if (is_string($message->subject) && trim($message->subject) !== '') {
                    $mail->subject($message->subject);
                }

                if (is_string($message->from) && trim($message->from) !== '') {
                    $fromName = is_array($message->meta) ? ($message->meta['from_name'] ?? null) : null;

                    if (is_string($fromName) && trim($fromName) !== '') {
                        $mail->from($message->from, trim($fromName));
                    } else {
                        $mail->from($message->from);
                    }
                }

                $replyTo = is_array($message->meta) ? ($message->meta['reply_to_email'] ?? null) : null;

                if (is_string($replyTo) && trim($replyTo) !== '') {
                    $mail->replyTo($replyTo);
                }
            });

            return ProviderSendResult::accepted(
                provider: 'smtp',
                providerMessageId: 'smtp-'.Str::uuid()->toString(),
                status: 'sent',
            );
        } catch (Throwable $exception) {
            return ProviderSendResult::failed('smtp', $exception->getMessage());
        } finally {
            if (is_array($smtpOverrides)) {
                config()->set('mail.mailers.smtp', $originalSmtpConfig);
                config()->set('mail.from', $originalFrom);
            }
        }
    }
}
