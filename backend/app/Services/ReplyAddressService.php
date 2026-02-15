<?php

namespace App\Services;

use App\Models\Message;
use Illuminate\Support\Str;

class ReplyAddressService
{
    /**
     * Ensure one outbound message has unique reply threading metadata.
     */
    public function ensureReplyMetadata(Message $message): Message
    {
        if ($message->channel !== 'email' || $message->direction !== 'outbound') {
            return $message;
        }

        $threadKey = $message->thread_key ?: $this->threadKey($message);
        $replyToken = $message->reply_token ?: $this->generateReplyToken();
        $replyToEmail = $message->reply_to_email ?: $this->buildReplyAddress(
            (int) $message->tenant_id,
            (int) $message->id,
            $replyToken
        );

        $message->forceFill([
            'thread_key' => $threadKey,
            'reply_token' => $replyToken,
            'reply_to_email' => $replyToEmail,
        ])->save();

        return $message->refresh();
    }

    /**
     * Build stable thread key for outbound + inbound grouping.
     */
    public function threadKey(Message $message): string
    {
        $campaignId = $message->campaign_id ?? 0;
        $leadId = $message->lead_id ?? 0;
        $channel = $message->channel ?: 'email';

        return implode(':', [
            'tenant',
            (int) $message->tenant_id,
            'campaign',
            (int) $campaignId,
            'lead',
            (int) $leadId,
            'channel',
            $channel,
        ]);
    }

    /**
     * Resolve stored token from a reply-to mailbox address.
     */
    public function tokenFromAddress(?string $address): ?string
    {
        if (! is_string($address) || trim($address) === '') {
            return null;
        }

        if (! preg_match('/reply\\+[A-Za-z0-9\\.-]+\\.([A-Za-z0-9]{32})@/i', $address, $matches)) {
            return null;
        }

        return $matches[1] ?? null;
    }

    /**
     * Build mailbox address for inbound reply capture.
     */
    public function buildReplyAddress(int $tenantId, int $messageId, string $token): string
    {
        $domain = (string) config('mail.reply_domain', config('app.domain', 'localhost'));
        $domain = trim($domain);

        if ($domain === '') {
            $domain = 'localhost';
        }

        return sprintf('reply+%d.%d.%s@%s', $tenantId, $messageId, $token, $domain);
    }

    /**
     * Generate compact thread token.
     */
    private function generateReplyToken(): string
    {
        return Str::lower(Str::random(32));
    }
}

