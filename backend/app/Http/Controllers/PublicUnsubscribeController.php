<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Lead;
use App\Models\Tenant;
use App\Models\Unsubscribe;
use App\Support\UnsubscribeToken;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PublicUnsubscribeController extends Controller
{
    /**
     * Handle unsubscribe requests from tokenized links.
     */
    public function __invoke(string $token, Request $request, UnsubscribeToken $unsubscribeToken): View
    {
        $payload = $unsubscribeToken->parse($token);

        if (! is_array($payload)) {
            abort(404, 'Invalid or expired unsubscribe token.');
        }

        $tenantId = (int) ($payload['tenant_id'] ?? 0);
        $leadId = isset($payload['lead_id']) ? (int) $payload['lead_id'] : null;
        $channel = (string) ($payload['channel'] ?? '');
        $value = (string) ($payload['value'] ?? '');

        if ($tenantId <= 0 || $channel === '' || $value === '') {
            abort(404, 'Invalid unsubscribe token payload.');
        }

        if (! Tenant::query()->whereKey($tenantId)->where('is_active', true)->exists()) {
            abort(404, 'Tenant not found.');
        }

        app(TenantContext::class)->setTenant($tenantId);

        $result = DB::transaction(function () use ($request, $tenantId, $leadId, $channel, $value): array {
            $lead = $this->resolveLead($tenantId, $leadId, $channel, $value);

            if ($lead !== null) {
                $settings = is_array($lead->settings) ? $lead->settings : [];
                $consent = is_array($settings['consent'] ?? null) ? $settings['consent'] : [];
                $consent[$channel] = false;
                $consent[$channel.'_unsubscribed_at'] = now()->toIso8601String();
                $settings['consent'] = $consent;

                $lead->forceFill([
                    'email_consent' => $channel === 'email' ? false : $lead->email_consent,
                    'consent_updated_at' => now(),
                    'settings' => $settings,
                ])->save();
            }

            $unsubscribe = Unsubscribe::query()->updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'channel' => $channel,
                    'value' => $value,
                ],
                [
                    'lead_id' => $lead?->id,
                    'reason' => 'user_unsubscribe',
                    'source' => 'unsubscribe_link',
                    'meta' => [
                        'ip' => $request->ip(),
                        'user_agent' => $request->userAgent(),
                    ],
                    'unsubscribed_at' => now(),
                ],
            );

            Activity::query()->create([
                'tenant_id' => $tenantId,
                'actor_id' => null,
                'type' => 'lead.unsubscribed',
                'subject_type' => $lead ? Lead::class : null,
                'subject_id' => $lead?->id,
                'description' => 'Unsubscribe link was used.',
                'properties' => [
                    'channel' => $channel,
                    'value' => $value,
                    'unsubscribe_id' => $unsubscribe->id,
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ],
            ]);

            return [
                'lead' => $lead,
                'unsubscribe' => $unsubscribe,
            ];
        });

        return view('unsubscribe.success', [
            'channel' => $channel,
            'value' => $value,
            'lead' => $result['lead'],
            'unsubscribe' => $result['unsubscribe'],
        ]);
    }

    /**
     * Resolve lead from token payload context.
     */
    private function resolveLead(int $tenantId, ?int $leadId, string $channel, string $value): ?Lead
    {
        if ($leadId !== null && $leadId > 0) {
            return Lead::query()
                ->where('tenant_id', $tenantId)
                ->whereKey($leadId)
                ->first();
        }

        if ($channel === 'email') {
            return Lead::query()
                ->where('tenant_id', $tenantId)
                ->where('email', $value)
                ->first();
        }

        return null;
    }
}
