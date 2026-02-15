<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Message;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InboxController extends Controller
{
    /**
     * List conversation messages across channels.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'leads.view');

        $payload = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'lead_id' => ['nullable', 'integer', 'exists:leads,id'],
            'channel' => ['nullable', 'string', 'max:24'],
            'direction' => ['nullable', 'string', 'max:24'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Message::query()
            ->with('lead:id,first_name,last_name,email,phone,company,status,interest,service,meta')
            ->orderByDesc('id');

        if (! empty($payload['lead_id'])) {
            $query->where('lead_id', (int) $payload['lead_id']);
        }

        if (! empty($payload['channel'])) {
            $query->where('channel', $payload['channel']);
        }

        if (! empty($payload['direction'])) {
            $query->where('direction', $payload['direction']);
        }

        if (! empty($payload['search'])) {
            $search = $payload['search'];
            $query->where(function (Builder $builder) use ($search): void {
                $builder
                    ->where('subject', 'like', "%{$search}%")
                    ->orWhere('body', 'like', "%{$search}%")
                    ->orWhere('to', 'like', "%{$search}%")
                    ->orWhere('from', 'like', "%{$search}%");
            });
        }

        $rows = $query->paginate((int) ($payload['per_page'] ?? 30))->withQueryString();

        return response()->json($rows);
    }

    /**
     * Fetch one thread timeline by key.
     */
    public function thread(Request $request, string $threadKey): JsonResponse
    {
        $this->authorizePermission($request, 'leads.view');

        $rows = Message::query()
            ->with('lead:id,first_name,last_name,email,phone,company,status,interest,service,meta')
            ->where('thread_key', $threadKey)
            ->orderBy('id')
            ->get();

        return response()->json([
            'thread_key' => $threadKey,
            'messages' => $rows,
        ]);
    }
}
