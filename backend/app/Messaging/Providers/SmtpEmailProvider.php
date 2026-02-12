<?php

namespace App\Messaging\Providers;

use App\Messaging\Contracts\EmailProviderInterface;
use App\Messaging\DTO\OutgoingMessageData;
use App\Messaging\DTO\ProviderSendResult;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

class SmtpEmailProvider implements EmailProviderInterface
{
    /**
     * Send email through Laravel's configured SMTP mailer.
     */
    public function send(OutgoingMessageData $message): ProviderSendResult
    {
        try {
            $body = (string) ($message->body ?? '');

            if ($body === '') {
                return ProviderSendResult::failed('smtp', 'Email body is empty.');
            }

            Mail::mailer(config('mail.default'))->html($body, function ($mail) use ($message): void {
                $mail->to($message->to);

                if (is_string($message->subject) && trim($message->subject) !== '') {
                    $mail->subject($message->subject);
                }

                if (is_string($message->from) && trim($message->from) !== '') {
                    $mail->from($message->from);
                }
            });

            return ProviderSendResult::accepted(
                provider: 'smtp',
                providerMessageId: 'smtp-'.Str::uuid()->toString(),
                status: 'sent',
            );
        } catch (Throwable $exception) {
            return ProviderSendResult::failed('smtp', $exception->getMessage());
        }
    }
}
