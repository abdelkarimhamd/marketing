<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    /**
     * Grant super-admin full access to user actions.
     */
    public function before(User $user, string $ability): ?bool
    {
        return $user->isSuperAdmin() ? true : null;
    }

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->isTenantAdmin();
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, User $model): bool
    {
        if ($user->isTenantAdmin()) {
            return $user->belongsToTenant($model->tenant_id);
        }

        return $user->id === $model->id;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->isTenantAdmin();
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, User $model): bool
    {
        if ($user->isTenantAdmin()) {
            if (! $user->belongsToTenant($model->tenant_id)) {
                return false;
            }

            return ! $model->isSuperAdmin();
        }

        return $user->id === $model->id;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, User $model): bool
    {
        if (! $user->isTenantAdmin()) {
            return false;
        }

        if (! $user->belongsToTenant($model->tenant_id)) {
            return false;
        }

        if ($user->id === $model->id) {
            return false;
        }

        return ! $model->isSuperAdmin();
    }
}
