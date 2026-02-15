<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\BrandResolutionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicBrandingController extends Controller
{
    /**
     * Return tenant branding resolved from public domain/header context.
     */
    public function __invoke(Request $request, BrandResolutionService $brandResolutionService): JsonResponse
    {
        $tenant = $request->attributes->get('tenant');

        if (! $tenant instanceof Tenant) {
            abort(422, 'Tenant context could not be resolved.');
        }

        $brand = $brandResolutionService->resolveForTenant($tenant, $request);
        $branding = $brandResolutionService->mergedBranding($tenant, $brand);

        return response()->json([
            'tenant' => [
                'id' => $tenant->id,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'domain' => $tenant->domain,
                'timezone' => $tenant->timezone,
                'locale' => $tenant->locale,
                'currency' => $tenant->currency,
            ],
            'brand' => $brand?->only([
                'id',
                'name',
                'slug',
                'landing_domain',
                'email_from_address',
                'sms_sender_id',
                'whatsapp_phone_number_id',
            ]),
            'branding' => $branding,
            'landing_page' => is_array($brand?->landing_page) ? $brand->landing_page : [],
            'theme' => data_get($branding, 'landing_theme', 'default'),
        ]);
    }
}
