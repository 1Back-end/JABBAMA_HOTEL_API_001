<?php

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

if (! function_exists('load_permissions')) {
    /**
     * Retourne toutes les permissions d’un utilisateur
     *
     * @param User $user
     * @return array
     */
    function load_permissions(User $user): array
    {
        // Permissions directes de l’utilisateur
        $permissions = $user->permissions()
            ->where('permissions.active', true)
            ->wherePivot('active', true)
            ->pluck('name')
            ->toArray();

        // Rôles actifs de l’utilisateur
        $roles = $user->roles()
            ->where('roles.active', true)
            ->wherePivot('active', true)
            ->get();

        // Permissions par rôle
        $permissionsByRole = collect();
        foreach ($roles as $role) {
            $permissionByRole = $role->permissions()
                ->where('permissions.active', true)
                ->wherePivot('active', true)
                ->pluck('permissions.name')
                ->toArray();

            $permissionsByRole->push(...$permissionByRole);
        }

        // Fusionner et supprimer doublons
        return collect([...$permissions, ...$permissionsByRole])
            ->unique()
            ->flatten()
            ->toArray();
    }
}
