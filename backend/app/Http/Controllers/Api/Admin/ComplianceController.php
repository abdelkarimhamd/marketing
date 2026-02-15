<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\CountryComplianceRule;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ComplianceController extends Controller
{
    /**
     * Show tenant compliance settings and country rules.
     */
    public function show(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'settings.view', requireTenantContext: false);
        $tenant = $this->tenant($request);
        $settings = is_array($tenant->settings) ? $tenant->settings : [];

        return response()->json([
            'compliance' => [
                'quiet_hours' => data_get($settings, 'compliance.quiet_hours', [
                    'enabled' => true,
                    'start' => '22:00',
                    'end' => '08:00',
                    'timezone' => $tenant->timezone ?? 'UTC',
                ]),
                'frequency_caps' => data_get($settings, 'compliance.frequency_caps', [
                    'email' => 5,
                    'sms' => 3,
                    'whatsapp' => 2,
                ]),
            ],
            'country_rules' => CountryComplianceRule::query()
                ->withoutTenancy()
                ->where('tenant_id', $tenant->id)
                ->orderBy('country_code')
                ->orderBy('channel')
                ->get(),
        ]);
    }

    /**
     * Update tenant compliance settings.
     */
    public function update(Request $request): JsonResponse
    {
        $this->authorizePermission($request, 'settings.update', requireTenantContext: false);
        $tenant = $this->tenant($request);

        $payload = $request->validate([
            'quiet_hours' => ['nullable', 'array'],
            'quiet_hours.enabled' => ['nullable', 'boolean'],
            'quiet_hours.start' => ['nullable', 'date_format:H:i'],
            'quiet_hours.end' => ['nullable', 'date_format:H:i'],
            'quiet_hours.timezone' => ['nullable', 'string', 'max:64'],
            'frequency_caps' => ['nullable', 'array'],
            'frequency_caps.email' => ['nullable', 'integer', 'min:0', 'max:100'],
            'frequency_caps.sms' => ['nullable', 'integer', 'min:0', 'max:100'],
            'frequency_caps.whatsapp' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);

        $settings = is_array($tenant->settings) ? $tenant->settings : [];
        $compliance = is_array($settings['compliance'] ?? null) ? $settings['compliance'] : [];

        if (is_array($payload['quiet_hours'] ?? null)) {
            $compliance['quiet_hours'] = array_merge(
                is_array($compliance['quiet_hours'] ?? null) ? $compliance['quiet_hours'] : [],
                $payload['quiet_hours']
            );
        }

        if (is_array($payload['frequency_caps'] ?? null)) {
            $compliance['frequency_caps'] = array_merge(
                is_array($compliance['frequency_caps'] ?? null) ? $compliance['frequency_caps'] : [],
                $payload['frequency_caps']
            );
        }

        $settings['compliance'] = $compliance;
        $tenant->forceFill(['settings' => $settings])->save();

        return response()->json([
            'message' => 'Compliance settings updated.',
            'compliance' => $compliance,
        ]);
    }

    /**
     * Create/update country channel rule.
     */
    public function upsertCountryRule(Request $request, ?CountryComplianceRule $countryComplianceRule = null): JsonResponse
    {
        $this->authorizePermission($request, 'settings.update', requireTenantContext: false);
        $tenant = $this->tenant($request);

        $payload = $request->validate([
            'country_code' => ['required', 'string', 'max:8'],
            'channel' => ['required', Rule::in(['email', 'sms', 'whatsapp'])],
            'sender_id' => ['nullable', 'string', 'max:120'],
            'opt_out_keywords' => ['nullable', 'array'],
            'opt_out_keywords.*' => ['string', 'max:50'],
            'template_constraints' => ['nullable', 'array'],
            'template_constraints.template_only' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'settings' => ['nullable', 'array'],
        ]);

        $rule = $countryComplianceRule;

        if ($rule !== null && (int) $rule->tenant_id !== (int) $tenant->id) {
            abort(404, 'Country rule not found in tenant scope.');
        }

        if ($rule === null) {
            $rule = CountryComplianceRule::query()
                ->withoutTenancy()
                ->firstOrNew([
                    'tenant_id' => $tenant->id,
                    'country_code' => mb_strtoupper($payload['country_code']),
                    'channel' => $payload['channel'],
                ]);
        }

        $rule->fill([
            'tenant_id' => $tenant->id,
            'country_code' => mb_strtoupper($payload['country_code']),
            'channel' => $payload['channel'],
            'sender_id' => $payload['sender_id'] ?? null,
            'opt_out_keywords' => $payload['opt_out_keywords'] ?? [],
            'template_constraints' => $payload['template_constraints'] ?? [],
            'is_active' => $payload['is_active'] ?? true,
            'settings' => $payload['settings'] ?? [],
        ])->save();

        return response()->json([
            'message' => 'Country compliance rule saved.',
            'rule' => $rule,
        ], $countryComplianceRule ? 200 : 201);
    }

    /**
     * Delete country rule.
     */
    public function destroyCountryRule(Request $request, CountryComplianceRule $countryComplianceRule): JsonResponse
    {
        $this->authorizePermission($request, 'settings.update', requireTenantContext: false);
        $tenant = $this->tenant($request);

        if ((int) $countryComplianceRule->tenant_id !== (int) $tenant->id) {
            abort(404, 'Country rule not found in tenant scope.');
        }

        $countryComplianceRule->delete();

        return response()->json([
            'message' => 'Country compliance rule deleted.',
        ]);
    }

    private function tenant(Request $request): Tenant
    {
        $tenantId = $request->attributes->get('tenant_id');

        if (! is_int($tenantId) || $tenantId <= 0) {
            $requested = $request->query('tenant_id', $request->input('tenant_id'));

            if (is_numeric($requested) && (int) $requested > 0) {
                $tenantId = (int) $requested;
            }
        }

        if (! is_int($tenantId) || $tenantId <= 0) {
            abort(422, 'Tenant context is required.');
        }

        return Tenant::query()->whereKey($tenantId)->firstOrFail();
    }
}

