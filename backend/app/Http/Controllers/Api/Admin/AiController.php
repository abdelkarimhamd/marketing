<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\Message;
use App\Services\AiAssistantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiController extends Controller
{
    /**
     * Generate campaign copy variants.
     */
    public function campaignCopy(Request $request, AiAssistantService $aiService): JsonResponse
    {
        $this->authorizePermission($request, 'templates.create');
        $tenantId = $this->tenantId($request);

        $payload = $request->validate([
            'offer' => ['nullable', 'string', 'max:500'],
            'audience' => ['nullable', 'string', 'max:500'],
            'tone' => ['nullable', 'string', 'max:120'],
            'channel' => ['nullable', 'string', 'max:50'],
        ]);

        $result = $aiService->campaignCopy($payload, $tenantId, $request->user());

        return response()->json([
            'result' => $result,
        ]);
    }

    /**
     * Classify lead intent and return smart routing tag.
     */
    public function classifyLead(Request $request, Lead $lead, AiAssistantService $aiService): JsonResponse
    {
        $this->authorizePermission($request, 'leads.update');
        $result = $aiService->classifyLeadIntent($lead, $request->user());

        return response()->json([
            'lead_id' => $lead->id,
            'classification' => $result,
        ]);
    }

    /**
     * Generate reply suggestions for inbound message.
     */
    public function replySuggestions(Request $request, Message $message, AiAssistantService $aiService): JsonResponse
    {
        $this->authorizePermission($request, 'leads.update');

        if ($message->direction !== 'inbound') {
            abort(422, 'Reply suggestions are available for inbound messages.');
        }

        $result = $aiService->replySuggestions($message, $request->user());

        return response()->json([
            'message_id' => $message->id,
            'result' => $result,
        ]);
    }

    private function tenantId(Request $request): int
    {
        $tenantId = $request->attributes->get('tenant_id');

        if (is_int($tenantId) && $tenantId > 0) {
            return $tenantId;
        }

        $requested = $request->query('tenant_id', $request->input('tenant_id'));

        if (is_numeric($requested) && (int) $requested > 0) {
            return (int) $requested;
        }

        abort(422, 'Tenant context is required.');
    }
}

