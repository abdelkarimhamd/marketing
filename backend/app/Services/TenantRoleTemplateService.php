<?php

namespace App\Services;

use App\Models\TenantRole;
use App\Support\PermissionMatrix;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class TenantRoleTemplateService
{
    public function __construct(
        private readonly PermissionMatrix $permissionMatrix,
    ) {
    }

    /**
     * Return configured role templates.
     *
     * @return array<string, array{name: string, description: string, permissions: array<string, array<string, bool>>}>
     */
    public function templates(): array
    {
        return $this->permissionMatrix->templates();
    }

    /**
     * Seed/update system templates for one tenant.
     *
     * @return Collection<string, TenantRole>
     */
    public function ensureTenantTemplates(int $tenantId, ?int $actorUserId = null): Collection
    {
        $collection = collect();

        foreach ($this->templates() as $key => $template) {
            $role = TenantRole::query()
                ->withoutTenancy()
                ->updateOrCreate(
                    [
                        'tenant_id' => $tenantId,
                        'slug' => $this->templateSlug((string) $key),
                    ],
                    [
                        'name' => $template['name'],
                        'description' => $template['description'],
                        'permissions' => $template['permissions'],
                        'is_system' => true,
                        'updated_by' => $actorUserId,
                        'created_by' => $actorUserId,
                    ]
                );

            $collection->put((string) $key, $role);
        }

        return $collection;
    }

    /**
     * Resolve canonical slug for one template key.
     */
    public function templateSlug(string $templateKey): string
    {
        return 'template-'.Str::slug($templateKey);
    }
}
