<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Call;
use App\Models\Lead;
use App\Models\Tenant;
use App\Services\Telephony\TwilioTelephonyProvider;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TelephonyWebhookController extends Controller
{
    /**
     * Twilio webhook receiver for call status updates.
     */
    public function twilio(Request $request): Response
    {
        if ($request->isMethod('get')) {
            return response(
                '<?xml version="1.0" encoding="UTF-8"?><Response><Say voice="alice">Connecting your call.</Say></Response>',
                200,
                ['Content-Type' => 'text/xml; charset=UTF-8']
            );
        }

        $payload = $request->all();
        $providerCallId = trim((string) ($payload['CallSid'] ?? ''));

        if ($providerCallId === '') {
            return response('ok', 200);
        }

        $call = Call::query()
            ->withoutTenancy()
            ->where('provider', 'twilio')
            ->where('provider_call_id', $providerCallId)
            ->orderByDesc('id')
            ->first();

        if (! $call instanceof Call) {
            return response('ok', 200);
        }

        $tenant = Tenant::query()->whereKey((int) $call->tenant_id)->first();

        if (! $tenant instanceof Tenant) {
            return response('ok', 200);
        }

        $mapped = app(TwilioTelephonyProvider::class)->mapWebhookPayload($tenant, $payload);

        if (! is_array($mapped)) {
            return response('ok', 200);
        }

        $status = (string) ($mapped['status'] ?? $call->status);
        $isTerminal = in_array($status, ['completed', 'failed'], true);
        $meta = is_array($call->meta) ? $call->meta : [];
        $incomingMeta = is_array($mapped['meta'] ?? null) ? $mapped['meta'] : [];

        $call->forceFill([
            'status' => $status,
            'duration' => $mapped['duration'] ?? $call->duration,
            'recording_url' => $mapped['recording_url'] ?? $call->recording_url,
            'meta' => array_replace_recursive($meta, ['webhook' => $incomingMeta]),
            'ended_at' => $isTerminal ? now() : $call->ended_at,
        ])->save();

        if ($call->lead_id !== null) {
            Activity::query()->withoutTenancy()->create([
                'tenant_id' => (int) $call->tenant_id,
                'actor_id' => null,
                'type' => 'lead.call.webhook',
                'subject_type' => Lead::class,
                'subject_id' => (int) $call->lead_id,
                'description' => 'Telephony provider callback updated call status.',
                'properties' => [
                    'call_id' => (int) $call->id,
                    'provider' => $call->provider,
                    'provider_call_id' => $call->provider_call_id,
                    'status' => $call->status,
                    'duration' => $call->duration,
                ],
            ]);
        }

        return response('ok', 200);
    }
}
