<?php

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Spatie\Browsershot\Browsershot;
use Spatie\Browsershot\Exceptions\CouldNotTakeBrowsershot;


if (! function_exists('upload_media')) {
    /**
     * Enregistré un média dans le disk spécifié et dans la table médias
     *
     * @param Model $model
     * @param UploadedFile $file
     * @param string $name
     * @param string $disk
     * @param string $path
     * @param string|null $filename
     * @param Media|null $update
     * @return void
     */
    function upload_media(Model $model, UploadedFile $file, string $name, string $disk, string $path, string $filename = null, Media $update = null): void
    {
        $mimetype = $file->getClientMimeType();
        $extension = $file->getClientOriginalExtension();
        $fileName = $filename ? $filename . '.' . $extension : $file->getClientOriginalName();

        if ($update) {
            delete_media(
                $disk,
                $update->path . '/' . $update->filename,
                $update
            );
        }

        $file->storeAs(
            path: $path,
            name: $fileName,
            options: [
                'disk' => $disk
            ]
        );


        $model->medias()->create([
            'name' => $name,
            'disk' => $disk,
            'path' => $path,
            'filename' => $fileName,
            'mimetype' => $mimetype,
            'extension' => $extension
        ]);
    }
}

if (! function_exists('delete_media')) {
    /**
     * Supprimer le fichier dans le disk ou dans la table média
     *
     * @param string $disk
     * @param string $path
     * @param Media|null $media
     * @return void
     */
    function delete_media(string $disk, string $path, ?Media $media = null): void
    {
        Storage::disk($disk)->delete($path);
        $media?->delete();
    }
}


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
