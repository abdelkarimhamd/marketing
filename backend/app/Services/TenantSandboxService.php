<?php

namespace App\Services;

use App\Models\AssignmentRule;
use App\Models\Campaign;
use App\Models\CustomField;
use App\Models\Lead;
use App\Models\LeadForm;
use App\Models\Segment;
use App\Models\Template;
use App\Models\Tenant;
use App\Models\TenantSandbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TenantSandboxService
{
    /**
     * Create or refresh sandbox tenant clone.
     */
    public function createSandbox(Tenant $tenant, string $name, bool $anonymized = true): TenantSandbox
    {
        return DB::transaction(function () use ($tenant, $name, $anonymized): TenantSandbox {
            $sandbox = TenantSandbox::query()
                ->withoutTenancy()
                ->firstOrCreate(
                    [
                        'tenant_id' => $tenant->id,
                        'name' => $name,
                    ],
                    [
                        'status' => 'provisioning',
                        'anonymized' => $anonymized,
                    ]
                );

            $sandboxTenant = $sandbox->sandbox_tenant_id
                ? Tenant::query()->whereKey($sandbox->sandbox_tenant_id)->first()
                : null;

            if ($sandboxTenant === null) {
                $sandboxTenant = Tenant::query()->create([
                    'name' => $tenant->name.' Sandbox',
                    'slug' => Str::slug($tenant->slug.'-sandbox-'.Str::random(6)),
                    'domain' => null,
                    'settings' => array_merge(
                        is_array($tenant->settings) ? $tenant->settings : [],
                        ['sandbox_mode' => true, 'source_tenant_id' => $tenant->id]
                    ),
                    'branding' => $tenant->branding,
                    'timezone' => $tenant->timezone,
                    'locale' => $tenant->locale,
                    'currency' => $tenant->currency,
                    'sso_required' => false,
                    'is_active' => true,
                ]);
            }

            $this->cloneConfigs($tenant->id, $sandboxTenant->id, $anonymized);

            $sandbox->forceFill([
                'sandbox_tenant_id' => $sandboxTenant->id,
                'status' => 'ready',
                'anonymized' => $anonymized,
                'last_cloned_at' => now(),
            ])->save();

            return $sandbox->refresh();
        });
    }

    /**
     * Promote configuration only from sandbox back to production tenant.
     */
    public function promoteConfigurations(TenantSandbox $sandbox): TenantSandbox
    {
        if (! $sandbox->sandbox_tenant_id) {
            throw new \RuntimeException('Sandbox tenant is missing.');
        }

        DB::transaction(function () use ($sandbox): void {
            $this->cloneConfigs((int) $sandbox->sandbox_tenant_id, (int) $sandbox->tenant_id, true, true);

            $sandbox->forceFill([
                'last_promoted_at' => now(),
                'status' => 'promoted',
            ])->save();
        });

        return $sandbox->refresh();
    }

    /**
     * Clone tenant configuration objects.
     */
    private function cloneConfigs(
        int $sourceTenantId,
        int $targetTenantId,
        bool $anonymized,
        bool $configOnly = false
    ): void {
        $this->replaceRows(Segment::class, $sourceTenantId, $targetTenantId, ['name', 'slug', 'description', 'filters', 'rules_json', 'settings', 'is_active']);
        $this->replaceRows(Template::class, $sourceTenantId, $targetTenantId, ['name', 'slug', 'channel', 'subject', 'content', 'body_text', 'whatsapp_template_name', 'whatsapp_variables', 'settings', 'is_active']);
        $this->replaceRows(AssignmentRule::class, $sourceTenantId, $targetTenantId, ['name', 'is_active', 'priority', 'strategy', 'conditions', 'settings', 'auto_assign_on_intake', 'auto_assign_on_import']);
        $this->replaceRows(CustomField::class, $sourceTenantId, $targetTenantId, ['entity', 'name', 'slug', 'field_type', 'is_required', 'is_active', 'options', 'validation_rules', 'permissions']);
        $this->replaceRows(LeadForm::class, $sourceTenantId, $targetTenantId, ['name', 'slug', 'is_active', 'settings']);

        if ($configOnly) {
            return;
        }

        if ($anonymized) {
            Lead::query()
                ->withoutTenancy()
                ->where('tenant_id', $sourceTenantId)
                ->orderBy('id')
                ->chunkById(300, function ($rows) use ($targetTenantId): void {
                    foreach ($rows as $row) {
                        Lead::query()->withoutTenancy()->create([
                            'tenant_id' => $targetTenantId,
                            'first_name' => 'Lead',
                            'last_name' => '#'.$row->id,
                            'email' => null,
                            'phone' => null,
                            'company' => $row->company,
                            'city' => $row->city,
                            'country_code' => $row->country_code,
                            'interest' => $row->interest,
                            'service' => $row->service,
                            'title' => $row->title,
                            'status' => $row->status,
                            'source' => 'sandbox_clone',
                            'score' => $row->score,
                            'timezone' => $row->timezone,
                            'locale' => $row->locale,
                            'settings' => $row->settings,
                            'meta' => ['anonymized_from' => $row->id],
                        ]);
                    }
                });

            return;
        }

        $this->replaceRows(Campaign::class, $sourceTenantId, $targetTenantId, ['name', 'slug', 'description', 'channel', 'campaign_type', 'status', 'start_at', 'end_at', 'settings', 'metrics']);
    }

    /**
     * Delete target tenant rows and reinsert selected source columns.
     *
     * @param class-string<\Illuminate\Database\Eloquent\Model> $modelClass
     * @param list<string> $columns
     */
    private function replaceRows(string $modelClass, int $sourceTenantId, int $targetTenantId, array $columns): void
    {
        $modelClass::query()->withoutTenancy()->where('tenant_id', $targetTenantId)->delete();

        $sourceRows = $modelClass::query()
            ->withoutTenancy()
            ->where('tenant_id', $sourceTenantId)
            ->get();

        foreach ($sourceRows as $sourceRow) {
            $payload = ['tenant_id' => $targetTenantId];

            foreach ($columns as $column) {
                $payload[$column] = $sourceRow->{$column};
            }

            $modelClass::query()->withoutTenancy()->create($payload);
        }
    }
}
