<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Lead;
use App\Models\Tag;
use App\Models\Tenant;
use App\Services\LeadAssignmentService;
use App\Support\UnsubscribeToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PublicLeadController extends Controller
{
    /**
     * Store a public lead from website form or API source.
     */
    public function store(
        Request $request,
        UnsubscribeToken $unsubscribeToken,
        LeadAssignmentService $assignmentService
    ): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        if (! $tenant instanceof Tenant) {
            abort(422, 'Tenant context is missing.');
        }

        $data = $request->validate([
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email:rfc', 'max:255', 'required_without:phone'],
            'phone' => ['nullable', 'string', 'max:32', 'required_without:email'],
            'company' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:150'],
            'interest' => ['nullable', 'string', 'max:150'],
            'service' => ['nullable', 'string', 'max:150'],
            'title' => ['nullable', 'string', 'max:255'],
            'source' => ['nullable', 'string', 'max:100'],
            'message' => ['nullable', 'string', 'max:5000'],
            'tags' => ['nullable', 'array', 'max:25'],
            'tags.*' => ['string', 'max:80'],
            'meta' => ['nullable', 'array'],
            'email_consent' => ['nullable', 'boolean'],
            'auto_assign' => ['nullable', 'boolean'],
            'website' => ['prohibited'],
            'api_key' => ['nullable', 'string', 'max:255'],
        ]);

        /** @var Lead $lead */
        $lead = DB::transaction(function () use ($tenant, $request, $data): Lead {
            $meta = is_array($data['meta'] ?? null) ? $data['meta'] : [];
            $meta['intake'] = [
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'origin' => $request->header('Origin'),
                'referer' => $request->header('Referer'),
                'tenant_resolution_source' => $request->attributes->get('tenant_resolution_source'),
                'received_at' => now()->toIso8601String(),
            ];

            $lead = Lead::query()->create([
                'tenant_id' => $tenant->id,
                'first_name' => $data['first_name'] ?? null,
                'last_name' => $data['last_name'] ?? null,
                'email' => $data['email'] ?? null,
                'email_consent' => array_key_exists('email_consent', $data)
                    ? (bool) $data['email_consent']
                    : true,
                'consent_updated_at' => now(),
                'phone' => $data['phone'] ?? null,
                'company' => $data['company'] ?? null,
                'city' => $data['city'] ?? null,
                'interest' => $data['interest'] ?? null,
                'service' => $data['service'] ?? null,
                'title' => $data['title'] ?? null,
                'status' => 'new',
                'source' => $data['source']
                    ?? ($request->header('X-Api-Key') ? 'api' : 'website'),
                'meta' => $meta,
            ]);

            $tags = collect($data['tags'] ?? [])
                ->map(static fn (string $tag): string => trim($tag))
                ->filter()
                ->unique()
                ->values();

            if ($tags->isNotEmpty()) {
                $tagIds = [];

                foreach ($tags as $tagName) {
                    $tag = Tag::query()->firstOrCreate(
                        [
                            'tenant_id' => $tenant->id,
                            'slug' => Str::slug($tagName),
                        ],
                        [
                            'name' => $tagName,
                        ]
                    );

                    $tagIds[$tag->id] = ['tenant_id' => $tenant->id];
                }

                $lead->tags()->syncWithoutDetaching($tagIds);
            }

            Activity::query()->create([
                'tenant_id' => $tenant->id,
                'actor_id' => null,
                'type' => 'lead.intake.created',
                'subject_type' => Lead::class,
                'subject_id' => $lead->id,
                'description' => 'Lead created from public intake endpoint.',
                'properties' => [
                    'source' => $lead->source,
                    'message' => $data['message'] ?? null,
                    'tenant_resolution_source' => $request->attributes->get('tenant_resolution_source'),
                    'ip' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                ],
            ]);

            return $lead;
        });

        if (($data['auto_assign'] ?? true) && $lead->owner_id === null) {
            $assignmentService->assignLead($lead->refresh(), 'intake');
        }

        $unsubscribeUrl = null;

        if (is_string($lead->email) && $lead->email !== '') {
            $token = $unsubscribeToken->make(
                tenantId: (int) $lead->tenant_id,
                channel: 'email',
                value: $lead->email,
                leadId: (int) $lead->id,
            );

            $unsubscribeUrl = route('public.unsubscribe', ['token' => $token]);
        }

        return response()->json([
            'message' => 'Lead created successfully.',
            'lead' => $lead->load('tags'),
            'unsubscribe_url' => $unsubscribeUrl,
        ], 201);
    }
}
