<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Campaign;
use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CampaignLogController extends Controller
{
    /**
     * Return campaign execution logs.
     */
    public function index(Request $request, Campaign $campaign): JsonResponse
    {
        $this->authorizePermission($request, 'campaigns.view');

        $payload = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $messageIds = Message::query()
            ->withoutTenancy()
            ->where('tenant_id', $campaign->tenant_id)
            ->where('campaign_id', $campaign->id)
            ->pluck('id')
            ->all();

        $logs = Activity::query()
            ->withoutTenancy()
            ->where('tenant_id', $campaign->tenant_id)
            ->where(function ($query) use ($campaign, $messageIds): void {
                $query
                    ->where(function ($builder) use ($campaign): void {
                        $builder
                            ->where('subject_type', Campaign::class)
                            ->where('subject_id', $campaign->id);
                    })
                    ->orWhere(function ($builder) use ($messageIds): void {
                        if ($messageIds === []) {
                            $builder->whereRaw('1 = 0');

                            return;
                        }

                        $builder
                            ->where('subject_type', Message::class)
                            ->whereIn('subject_id', $messageIds);
                    });
            })
            ->with('actor:id,name,email')
            ->orderByDesc('id')
            ->paginate((int) ($payload['per_page'] ?? 25))
            ->withQueryString();

        return response()->json($logs);
    }

}
