<?php

namespace App\Support;

class PermissionMatrix
{
    /**
     * Build an empty (all false) matrix.
     *
     * @return array<string, array<string, bool>>
     */
    public function blankMatrix(): array
    {
        $matrix = [];

        foreach ($this->resources() as $resource => $actions) {
            $matrix[$resource] = [];

            foreach ($actions as $action) {
                $matrix[$resource][$action] = false;
            }
        }

        return $matrix;
    }

    /**
     * Build full (all true) matrix.
     *
     * @return array<string, array<string, bool>>
     */
    public function fullMatrix(): array
    {
        $matrix = [];

        foreach ($this->resources() as $resource => $actions) {
            $matrix[$resource] = [];

            foreach ($actions as $action) {
                $matrix[$resource][$action] = true;
            }
        }

        return $matrix;
    }

    /**
     * Normalize matrix payload to known resources/actions only.
     *
     * @param array<string, mixed>|null $payload
     * @return array<string, array<string, bool>>
     */
    public function normalizeMatrix(?array $payload): array
    {
        $normalized = $this->blankMatrix();

        if (! is_array($payload)) {
            return $normalized;
        }

        foreach ($this->resources() as $resource => $actions) {
            $resourcePayload = $payload[$resource] ?? null;

            if (! is_array($resourcePayload)) {
                continue;
            }

            foreach ($actions as $action) {
                if (array_key_exists($action, $resourcePayload)) {
                    $normalized[$resource][$action] = (bool) $resourcePayload[$action];
                }
            }
        }

        return $normalized;
    }

    /**
     * Build matrix from flat permission list.
     *
     * @param list<string> $permissions
     * @return array<string, array<string, bool>>
     */
    public function matrixFromPermissions(array $permissions): array
    {
        if (in_array('*', $permissions, true)) {
            return $this->fullMatrix();
        }

        $matrix = $this->blankMatrix();
        $allowed = array_fill_keys($permissions, true);

        foreach ($this->allPermissionKeys() as $permission) {
            if (! isset($allowed[$permission])) {
                continue;
            }

            [$resource, $action] = explode('.', $permission, 2);
            $matrix[$resource][$action] = true;
        }

        return $matrix;
    }

    /**
     * Flatten matrix into permission => bool map.
     *
     * @param array<string, mixed> $matrix
     * @return array<string, bool>
     */
    public function flattenMatrix(array $matrix): array
    {
        $normalized = $this->normalizeMatrix($matrix);
        $flat = [];

        foreach ($normalized as $resource => $actions) {
            foreach ($actions as $action => $allowed) {
                $flat["{$resource}.{$action}"] = (bool) $allowed;
            }
        }

        return $flat;
    }

    /**
     * Merge two matrices with OR semantics.
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $incoming
     * @return array<string, array<string, bool>>
     */
    public function mergeMatrices(array $base, array $incoming): array
    {
        $merged = $this->normalizeMatrix($base);
        $incomingNormalized = $this->normalizeMatrix($incoming);

        foreach ($merged as $resource => $actions) {
            foreach ($actions as $action => $allowed) {
                $merged[$resource][$action] = (bool) ($allowed || $incomingNormalized[$resource][$action]);
            }
        }

        return $merged;
    }

    /**
     * Determine if matrix allows one permission key.
     *
     * @param array<string, mixed> $matrix
     */
    public function allows(array $matrix, string $permission): bool
    {
        if (! str_contains($permission, '.')) {
            return false;
        }

        [$resource, $action] = explode('.', $permission, 2);
        $normalized = $this->normalizeMatrix($matrix);

        if (! isset($normalized[$resource])) {
            return false;
        }

        return (bool) ($normalized[$resource][$action] ?? false);
    }

    /**
     * Return all supported permission keys.
     *
     * @return list<string>
     */
    public function allPermissionKeys(): array
    {
        $keys = [];

        foreach ($this->resources() as $resource => $actions) {
            foreach ($actions as $action) {
                $keys[] = "{$resource}.{$action}";
            }
        }

        return $keys;
    }

    /**
     * Return configured role templates expanded into matrices.
     *
     * @return array<string, array{name: string, description: string, permissions: array<string, array<string, bool>>}>
     */
    public function templates(): array
    {
        $templates = config('rbac.templates', []);
        $result = [];

        foreach ($templates as $key => $template) {
            if (! is_array($template)) {
                continue;
            }

            $permissions = $template['permissions'] ?? [];
            $permissions = is_array($permissions) ? $permissions : [];

            $result[$key] = [
                'name' => (string) ($template['name'] ?? ucfirst((string) $key)),
                'description' => (string) ($template['description'] ?? ''),
                'permissions' => $this->matrixFromPermissions(array_values($permissions)),
            ];
        }

        return $result;
    }

    /**
     * Return resources/actions configuration.
     *
     * @return array<string, list<string>>
     */
    private function resources(): array
    {
        $resources = config('rbac.resources', []);

        if (! is_array($resources)) {
            return [];
        }

        $normalized = [];

        foreach ($resources as $resource => $actions) {
            if (! is_string($resource) || ! is_array($actions)) {
                continue;
            }

            $normalized[$resource] = array_values(array_unique(array_map(
                static fn (mixed $action): string => (string) $action,
                $actions
            )));
        }

        return $normalized;
    }
}
