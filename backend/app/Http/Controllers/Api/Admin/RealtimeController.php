<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\Message;
use App\Models\RealtimeEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RealtimeController extends Controller
{
    /**
     * Poll realtime events after one id.
     */
    public function events(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'dashboard.view');

        $payload = $request->validate([
            'after_id' => ['nullable', 'integer', 'min:0'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
            'event' => ['nullable', 'string', 'max:120'],
        ]);

        $query = RealtimeEvent::query()->orderBy('id');

        if (! empty($payload['after_id'])) {
            $query->where('id', '>', (int) $payload['after_id']);
        }

        if (! empty($payload['event'])) {
            $query->where('event_name', $payload['event']);
        }

        $limit = (int) ($payload['limit'] ?? 120);
        $rows = $query->limit($limit)->get();

        return response()->json([
            'events' => $rows,
            'last_id' => (int) ($rows->last()->id ?? ($payload['after_id'] ?? 0)),
        ]);
    }

    /**
     * Campaign live monitor status for queued/sent/failed.
     */
    public function campaignMonitor(Request $request, Campaign $campaign): JsonResponse
    {
        $this->authorizePermission($request, 'campaigns.view');

        $query = Message::query()
            ->where('campaign_id', $campaign->id)
            ->where('direction', 'outbound');

        $stats = [
            'queued' => (clone $query)->where('status', 'queued')->count(),
            'sent' => (clone $query)->whereIn('status', ['sent', 'delivered', 'opened', 'clicked', 'read'])->count(),
            'failed' => (clone $query)->where('status', 'failed')->count(),
            'inbound_replies' => Message::query()
                ->where('campaign_id', $campaign->id)
                ->where('direction', 'inbound')
                ->count(),
        ];

        return response()->json([
            'campaign_id' => $campaign->id,
            'campaign_status' => $campaign->status,
            'stats' => $stats,
        ]);
    }
}

