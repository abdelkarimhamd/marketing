<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ConsentEvent;
use App\Models\LeadPreference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConsentController extends Controller
{
    /**
     * List consent proof events.
     */
    public function events(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'leads.view');

        $payload = $request->validate([
            'lead_id' => ['nullable', 'integer', 'exists:leads,id'],
            'channel' => ['nullable', 'string', 'max:24'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = ConsentEvent::query()
            ->with('lead:id,first_name,last_name,email,phone');

        if (! empty($payload['lead_id'])) {
            $query->where('lead_id', (int) $payload['lead_id']);
        }

        if (! empty($payload['channel'])) {
            $query->where('channel', $payload['channel']);
        }

        $rows = $query->orderByDesc('id')
            ->paginate((int) ($payload['per_page'] ?? 25))
            ->withQueryString();

        return response()->json($rows);
    }

    /**
     * List preference center rows.
     */
    public function preferences(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'leads.view');

        $payload = $request->validate([
            'lead_id' => ['nullable', 'integer', 'exists:leads,id'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = LeadPreference::query()->with('lead:id,first_name,last_name,email,phone');

        if (! empty($payload['lead_id'])) {
            $query->where('lead_id', (int) $payload['lead_id']);
        }

        $rows = $query->orderByDesc('id')
            ->paginate((int) ($payload['per_page'] ?? 25))
            ->withQueryString();

        return response()->json($rows);
    }
}

