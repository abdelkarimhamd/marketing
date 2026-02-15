<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Activity;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Tag;
use App\Models\Team;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class GlobalSearchController extends Controller
{
    /**
     * Entity groups available in global search.
     *
     * @var list<string>
     */
    private const TYPES = ['leads', 'deals', 'activities', 'conversations'];

    /**
     * Run a tenant-wide global search with filters.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'leads.view');
        $tenantId = $this->resolveTenantIdStrict($request);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'types' => ['nullable', 'array'],
            'types.*' => ['string', Rule::in(self::TYPES)],
            'per_type' => ['nullable', 'integer', 'min:1', 'max:50'],
            'owner_id' => ['nullable', 'integer'],
            'team_id' => ['nullable', 'integer'],
            'tag_ids' => ['nullable', 'array'],
            'tag_ids.*' => ['integer'],
            'status' => ['nullable', 'array'],
            'status.*' => ['string', 'max:60'],
            'source' => ['nullable', 'array'],
            'source.*' => ['string', 'max:100'],
            'channel' => ['nullable', 'array'],
            'channel.*' => ['string', 'max:24'],
            'activity_type' => ['nullable', 'array'],
            'activity_type.*' => ['string', 'max:140'],
            'min_score' => ['nullable', 'integer', 'min:0'],
            'max_score' => ['nullable', 'integer', 'min:0'],
            'no_response_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ]);

        $query = trim((string) ($filters['q'] ?? ''));
        $types = $this->normalizeTypes($filters['types'] ?? null);
        $limit = (int) ($filters['per_type'] ?? 10);

        $this->guardReferenceFilters($tenantId, $filters);

        $results = [];
        $counts = [
            'leads' => 0,
            'deals' => 0,
            'activities' => 0,
            'conversations' => 0,
        ];

        if ($types->contains('leads')) {
            [$rows, $total] = $this->searchLeads($query, $filters, $limit, false);
            $results['leads'] = $rows;
            $counts['leads'] = $total;
        }

        if ($types->contains('deals')) {
            [$rows, $total] = $this->searchLeads($query, $filters, $limit, true);
            $results['deals'] = $rows;
            $counts['deals'] = $total;
        }

        if ($types->contains('activities')) {
            [$rows, $total] = $this->searchActivities($query, $filters, $limit);
            $results['activities'] = $rows;
            $counts['activities'] = $total;
        }

        if ($types->contains('conversations')) {
            [$rows, $total] = $this->searchConversations($query, $filters, $limit);
            $results['conversations'] = $rows;
            $counts['conversations'] = $total;
        }

        return response()->json([
            'query' => $query,
            'types' => $types->values()->all(),
            'filters' => [
                'owner_id' => $filters['owner_id'] ?? null,
                'team_id' => $filters['team_id'] ?? null,
                'tag_ids' => $this->normalizeIntList($filters['tag_ids'] ?? []),
                'status' => $this->normalizeStringList($filters['status'] ?? []),
                'source' => $this->normalizeStringList($filters['source'] ?? []),
                'channel' => $this->normalizeStringList($filters['channel'] ?? []),
                'activity_type' => $this->normalizeStringList($filters['activity_type'] ?? []),
                'min_score' => $filters['min_score'] ?? null,
                'max_score' => $filters['max_score'] ?? null,
                'no_response_days' => $filters['no_response_days'] ?? null,
                'date_from' => $filters['date_from'] ?? null,
                'date_to' => $filters['date_to'] ?? null,
            ],
            'counts' => [
                ...$counts,
                'total' => array_sum($counts),
            ],
            'results' => $results,
        ]);
    }

    /**
     * Search leads and deal-like pipeline rows.
     *
     * @return array{list<array<string, mixed>>, int}
     */
    private function searchLeads(string $query, array $filters, int $limit, bool $dealOnly): array
    {
        $leadQuery = $this->buildLeadSearchQuery(
            query: $query,
            filters: $filters,
            dealOnly: $dealOnly,
            includeDateFilter: true,
        )->with(['owner:id,name,email', 'team:id,name'])
            ->orderByDesc('updated_at');

        $total = (clone $leadQuery)->count();
        $rows = $leadQuery->limit($limit)->get();

        $mapped = $rows->map(function (Lead $lead) use ($dealOnly): array {
            return [
                'id' => (int) $lead->id,
                'entity' => $dealOnly ? 'deal' : 'lead',
                'name' => trim(($lead->first_name ?? '').' '.($lead->last_name ?? '')) ?: null,
                'email' => $lead->email,
                'phone' => $lead->phone,
                'company' => $lead->company,
                'city' => $lead->city,
                'status' => $lead->status,
                'score' => (int) ($lead->score ?? 0),
                'source' => $lead->source,
                'owner' => $lead->owner ? [
                    'id' => (int) $lead->owner->id,
                    'name' => $lead->owner->name,
                    'email' => $lead->owner->email,
                ] : null,
                'team' => $lead->team ? [
                    'id' => (int) $lead->team->id,
                    'name' => $lead->team->name,
                ] : null,
                'updated_at' => optional($lead->updated_at)?->toISOString(),
                'created_at' => optional($lead->created_at)?->toISOString(),
            ];
        })->values()->all();

        return [$mapped, $total];
    }

    /**
     * Search conversations (messages) with full-text.
     *
     * @return array{list<array<string, mixed>>, int}
     */
    private function searchConversations(string $query, array $filters, int $limit): array
    {
        $messageQuery = Message::query()
            ->with('lead:id,first_name,last_name,email,phone,company,status,source,owner_id,team_id,score')
            ->orderByDesc('id');

        $channels = $this->normalizeStringList($filters['channel'] ?? []);
        if ($channels->isNotEmpty()) {
            $messageQuery->whereIn('channel', $channels->all());
        }

        $this->applyDateRange($messageQuery, $filters, 'created_at');

        if ($this->hasLeadScopedFilters($filters)) {
            $messageQuery->whereHas('lead', function (Builder $leadQuery) use ($filters): void {
                $this->applyLeadFilters($leadQuery, $filters, includeDateFilter: false);
            });
        }

        if ($query !== '') {
            $messageQuery->where(function (Builder $builder) use ($query): void {
                $builder
                    ->where('subject', 'like', "%{$query}%")
                    ->orWhere('body', 'like', "%{$query}%")
                    ->orWhere('to', 'like', "%{$query}%")
                    ->orWhere('from', 'like', "%{$query}%")
                    ->orWhere('thread_key', 'like', "%{$query}%")
                    ->orWhereHas('lead', function (Builder $leadBuilder) use ($query): void {
                        $this->applyLeadTextSearch($leadBuilder, $query);
                    });
            });
        }

        $total = (clone $messageQuery)->count();
        $rows = $messageQuery->limit($limit)->get();

        $mapped = $rows->map(function (Message $message): array {
            $lead = $message->lead;
            $leadName = trim((string) ($lead?->first_name ?? '').' '.(string) ($lead?->last_name ?? ''));

            return [
                'id' => (int) $message->id,
                'entity' => 'conversation',
                'thread_key' => $message->thread_key,
                'channel' => $message->channel,
                'direction' => $message->direction,
                'status' => $message->status,
                'subject' => $message->subject,
                'body_excerpt' => Str::limit(trim((string) strip_tags((string) $message->body)), 180),
                'to' => $message->to,
                'from' => $message->from,
                'lead' => $lead ? [
                    'id' => (int) $lead->id,
                    'name' => $leadName !== '' ? $leadName : null,
                    'email' => $lead->email,
                    'phone' => $lead->phone,
                    'company' => $lead->company,
                    'status' => $lead->status,
                ] : null,
                'created_at' => optional($message->created_at)?->toISOString(),
            ];
        })->values()->all();

        return [$mapped, $total];
    }

    /**
     * Search activities timeline.
     *
     * @return array{list<array<string, mixed>>, int}
     */
    private function searchActivities(string $query, array $filters, int $limit): array
    {
        $activityQuery = Activity::query()
            ->with('actor:id,name,email')
            ->orderByDesc('id');

        $activityTypes = $this->normalizeStringList($filters['activity_type'] ?? []);
        if ($activityTypes->isNotEmpty()) {
            $activityQuery->whereIn('type', $activityTypes->all());
        }

        $this->applyDateRange($activityQuery, $filters, 'created_at');

        if ($this->hasLeadScopedFilters($filters)) {
            $leadIdsSubQuery = $this->buildLeadSearchQuery(
                query: '',
                filters: $filters,
                dealOnly: false,
                includeDateFilter: false,
            )->select('leads.id');

            $messageIdsSubQuery = Message::query()
                ->select('messages.id')
                ->whereIn('lead_id', $leadIdsSubQuery);

            $activityQuery->where(function (Builder $builder) use ($leadIdsSubQuery, $messageIdsSubQuery): void {
                $builder
                    ->where(function (Builder $leadBuilder) use ($leadIdsSubQuery): void {
                        $leadBuilder
                            ->where('subject_type', Lead::class)
                            ->whereIn('subject_id', $leadIdsSubQuery);
                    })
                    ->orWhere(function (Builder $messageBuilder) use ($messageIdsSubQuery): void {
                        $messageBuilder
                            ->where('subject_type', Message::class)
                            ->whereIn('subject_id', $messageIdsSubQuery);
                    });
            });
        }

        if ($query !== '') {
            $activityQuery->where(function (Builder $builder) use ($query): void {
                $builder
                    ->where('type', 'like', "%{$query}%")
                    ->orWhere('description', 'like', "%{$query}%");
            });
        }

        $total = (clone $activityQuery)->count();
        $rows = $activityQuery->limit($limit)->get();

        $mapped = $rows->map(function (Activity $activity): array {
            return [
                'id' => (int) $activity->id,
                'entity' => 'activity',
                'type' => $activity->type,
                'description' => $activity->description,
                'subject_type' => $activity->subject_type,
                'subject_id' => $activity->subject_id !== null ? (int) $activity->subject_id : null,
                'actor' => $activity->actor ? [
                    'id' => (int) $activity->actor->id,
                    'name' => $activity->actor->name,
                    'email' => $activity->actor->email,
                ] : null,
                'created_at' => optional($activity->created_at)?->toISOString(),
            ];
        })->values()->all();

        return [$mapped, $total];
    }

    /**
     * Build one lead search query with all supported filters.
     */
    private function buildLeadSearchQuery(
        string $query,
        array $filters,
        bool $dealOnly,
        bool $includeDateFilter
    ): Builder {
        $leadQuery = Lead::query();
        $this->applyLeadFilters($leadQuery, $filters, $includeDateFilter);

        if ($dealOnly) {
            $leadQuery->where('status', '!=', 'new');
        }

        if ($query !== '') {
            $leadQuery->where(function (Builder $builder) use ($query): void {
                $this->applyLeadTextSearch($builder, $query);
            });
        }

        return $leadQuery;
    }

    /**
     * Apply reusable lead filters to a query.
     */
    private function applyLeadFilters(Builder $leadQuery, array $filters, bool $includeDateFilter): void
    {
        if (isset($filters['owner_id'])) {
            $leadQuery->where('owner_id', (int) $filters['owner_id']);
        }

        if (isset($filters['team_id'])) {
            $leadQuery->where('team_id', (int) $filters['team_id']);
        }

        $statuses = $this->normalizeStringList($filters['status'] ?? []);
        if ($statuses->isNotEmpty()) {
            $leadQuery->whereIn('status', $statuses->all());
        }

        $sources = $this->normalizeStringList($filters['source'] ?? []);
        if ($sources->isNotEmpty()) {
            $leadQuery->whereIn('source', $sources->all());
        }

        if (isset($filters['min_score'])) {
            $leadQuery->where('score', '>=', (int) $filters['min_score']);
        }

        if (isset($filters['max_score'])) {
            $leadQuery->where('score', '<=', (int) $filters['max_score']);
        }

        if ($includeDateFilter) {
            $this->applyDateRange($leadQuery, $filters, 'created_at');
        }

        $tagIds = $this->normalizeIntList($filters['tag_ids'] ?? []);
        if ($tagIds->isNotEmpty()) {
            $leadQuery->whereHas('tags', function (Builder $builder) use ($tagIds): void {
                $builder->whereIn('tags.id', $tagIds->all());
            });
        }

        if (isset($filters['no_response_days'])) {
            $threshold = Carbon::now()->subDays((int) $filters['no_response_days']);

            $leadQuery->where(function (Builder $builder) use ($threshold): void {
                $builder
                    ->whereDoesntHave('messages', function (Builder $messageQuery): void {
                        $messageQuery->where('direction', 'inbound');
                    })
                    ->orWhereHas('messages', function (Builder $messageQuery) use ($threshold): void {
                        $messageQuery
                            ->where('direction', 'inbound')
                            ->where('created_at', '<', $threshold);
                    });
            });
        }
    }

    /**
     * Apply lead full-text matching.
     */
    private function applyLeadTextSearch(Builder $leadQuery, string $query): void
    {
        $leadQuery
            ->where('first_name', 'like', "%{$query}%")
            ->orWhere('last_name', 'like', "%{$query}%")
            ->orWhere('email', 'like', "%{$query}%")
            ->orWhere('phone', 'like', "%{$query}%")
            ->orWhere('company', 'like', "%{$query}%")
            ->orWhere('city', 'like', "%{$query}%")
            ->orWhere('interest', 'like', "%{$query}%")
            ->orWhere('service', 'like', "%{$query}%")
            ->orWhere('title', 'like', "%{$query}%");
    }

    /**
     * Apply date range filtering to one timestamp column.
     */
    private function applyDateRange(Builder $query, array $filters, string $column): void
    {
        if (! empty($filters['date_from'])) {
            $query->whereDate($column, '>=', Carbon::parse((string) $filters['date_from'])->toDateString());
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate($column, '<=', Carbon::parse((string) $filters['date_to'])->toDateString());
        }
    }

    /**
     * Determine if lead-scoped filters were requested.
     */
    private function hasLeadScopedFilters(array $filters): bool
    {
        return isset($filters['owner_id'])
            || isset($filters['team_id'])
            || isset($filters['min_score'])
            || isset($filters['max_score'])
            || isset($filters['no_response_days'])
            || ! empty($filters['tag_ids'])
            || ! empty($filters['status'])
            || ! empty($filters['source']);
    }

    /**
     * Validate owner/team/tag references against active tenant.
     */
    private function guardReferenceFilters(int $tenantId, array $filters): void
    {
        if (isset($filters['owner_id'])) {
            $exists = User::query()
                ->withoutTenancy()
                ->where('tenant_id', $tenantId)
                ->whereKey((int) $filters['owner_id'])
                ->exists();

            if (! $exists) {
                abort(422, 'owner_id does not belong to this tenant.');
            }
        }

        if (isset($filters['team_id'])) {
            $exists = Team::query()
                ->withoutTenancy()
                ->where('tenant_id', $tenantId)
                ->whereKey((int) $filters['team_id'])
                ->exists();

            if (! $exists) {
                abort(422, 'team_id does not belong to this tenant.');
            }
        }

        $tagIds = $this->normalizeIntList($filters['tag_ids'] ?? []);
        if ($tagIds->isEmpty()) {
            return;
        }

        $validCount = Tag::query()
            ->withoutTenancy()
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $tagIds->all())
            ->count();

        if ($validCount !== $tagIds->count()) {
            abort(422, 'One or more tag_ids do not belong to this tenant.');
        }
    }

    /**
     * Resolve tenant id from request context.
     */
    private function resolveTenantIdStrict(Request $request): int
    {
        $tenantId = $this->resolveTenantIdForPermission($request, $request->user());

        if ($tenantId !== null && Tenant::query()->whereKey($tenantId)->exists()) {
            return $tenantId;
        }

        abort(422, 'Tenant context is required.');
    }

    /**
     * Normalize requested search types.
     */
    private function normalizeTypes(mixed $types): Collection
    {
        if (! is_array($types) || $types === []) {
            return collect(self::TYPES);
        }

        return collect($types)
            ->map(static fn (mixed $item): string => strtolower(trim((string) $item)))
            ->filter(static fn (string $item): bool => in_array($item, self::TYPES, true))
            ->unique()
            ->values();
    }

    /**
     * @param mixed $items
     */
    private function normalizeStringList(mixed $items): Collection
    {
        if (! is_array($items)) {
            return collect();
        }

        return collect($items)
            ->map(static fn (mixed $item): string => trim((string) $item))
            ->filter(static fn (string $item): bool => $item !== '')
            ->values();
    }

    /**
     * @param mixed $items
     */
    private function normalizeIntList(mixed $items): Collection
    {
        if (! is_array($items)) {
            return collect();
        }

        return collect($items)
            ->map(static fn (mixed $item): int => (int) $item)
            ->filter(static fn (int $item): bool => $item > 0)
            ->unique()
            ->values();
    }
}
