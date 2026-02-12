<?php

namespace App\Http\Middleware;

use App\Tenancy\PublicTenantResolver;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolvePublicTenant
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $resolution = app(PublicTenantResolver::class)->resolve($request);

        if ($resolution === null) {
            abort(
                422,
                'Unable to resolve tenant. Provide X-Tenant-Slug/X-Tenant-ID, a mapped domain, or a valid API key.'
            );
        }

        $tenant = $resolution['tenant'];
        $source = $resolution['source'];

        app(TenantContext::class)->setTenant($tenant->id);

        $request->attributes->set('tenant_id', $tenant->id);
        $request->attributes->set('tenant', $tenant);
        $request->attributes->set('tenant_resolution_source', $source);

        return $next($request);
    }
}
