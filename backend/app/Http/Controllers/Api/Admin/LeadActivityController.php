<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Lead;
use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

class LeadActivityController extends Controller
{
    /**
     * Return activities linked to a lead.
     */
    public function index(Request $request, Lead $lead): JsonResponse
    {
        $this->authorizePermission($request, 'leads.view');

        $payload = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $perPage = (int) ($payload['per_page'] ?? 25);
        $page = max(1, (int) $request->input('page', 1));

        $leadActivities = Activity::query()
            ->where('subject_type', Lead::class)
            ->where('subject_id', $lead->id)
            ->with('actor:id,name,email')
            ->get();

        $messageIds = Message::query()
            ->where('lead_id', $lead->id)
            ->pluck('id');

        $messageActivities = Activity::query()
            ->where('subject_type', Message::class)
            ->whereIn('subject_id', $messageIds)
            ->with('actor:id,name,email')
            ->get();

        $combined = $leadActivities
            ->concat($messageActivities)
            ->sortByDesc('id')
            ->values();

        $slice = $combined->slice(($page - 1) * $perPage, $perPage)->values();

        $activities = new LengthAwarePaginator(
            items: $slice,
            total: $combined->count(),
            perPage: $perPage,
            currentPage: $page,
            options: [
                'path' => $request->url(),
                'query' => $request->query(),
            ],
        );

        return response()->json($activities);
    }

}
