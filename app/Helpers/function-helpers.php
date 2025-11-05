<?php

use App\Models\Medias;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Spatie\Browsershot\Exceptions\CouldNotTakeBrowsershot;
use Spatie\Browsershot\Browsershot;



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
     * @param Medias|null $update
     * @return void
     */
    function upload_media(Model $model, UploadedFile $file, string $name, string $disk, string $path, string $filename = null, Medias $update = null): void
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


function save_browser_shot_pdf(string $view, array $data, string $folderPath, string $path, string $format = 'a4', string $direction = '', string $header = '', string $footer = '', array $margins = [0, 0, 0, 0]): void
{
    $bootstrapPath = public_path('assets/bootstrap/css/bootstrap.min.css');
    $bootstrapContent = file_get_contents($bootstrapPath);
    $data = array_merge($data, ['bootstrap' => $bootstrapContent]);

    $folderPath = public_path($folderPath);
    if (!File::exists($folderPath)) {
        File::makeDirectory($folderPath, 0755, true);
    }


    $browserShot = Browsershot::html(view($view, $data)->render())
        ->format($format)
        ->margins($margins[0], $margins[1], $margins[2], $margins[3])
        ->showBackground();


    if (env('APP_ENV') == "production") {
        $browserShot->setChromePath('C:\chrome-headless\chrome-headless-shell.exe');
    }


    if ($header) {
        $browserShot->showBrowserHeaderAndFooter()
            ->hideFooter()
            ->headerHtml(view($header, $data)->render());
    }

    if ($footer) {
        $browserShot->showBrowserHeaderAndFooter()
            ->hideHeader()
            ->footerHtml(view($footer, $data)->render());
    }

    if ($direction) {
        $browserShot->landscape();
    }

    $browserShot->save($path);
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
    function delete_media(string $disk, string $path, ?Medias $media = null): void
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
