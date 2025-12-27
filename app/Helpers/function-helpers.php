<?php

use App\DTO\PurchaseOrderFilterData;
use App\DTO\SupplyFilterData;
use App\Models\Medias;
use App\Models\PurchaseOrder;
use App\Models\StockAdjustment;
use App\Models\Supply;
use App\Models\User;
use App\Models\Warehouse;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Spatie\Browsershot\Exceptions\CouldNotTakeBrowsershot;
use Spatie\Browsershot\Browsershot;
use Illuminate\Pagination\LengthAwarePaginator;


/**
 * Retourne la requête ou la collection paginée des bons de commande filtrés
 *
 * @param \App\DTO\StockAdjustmentFilterData $filter
 * @param bool $paginate
 * @return Builder|LengthAwarePaginator
 */
if (!function_exists('filter_stocks_adjustment')) {
    function filter_stocks_adjustment(
        \App\DTO\StockAdjustmentFilterData $filterData,
        bool $paginate = true
    ): Builder|LengthAwarePaginator {

        $query = StockAdjustment::with([
            'warehouse',
            'items.product',
            'creator',
            'updater',
            'validator',
        ]);

        // 🔹 Filtres simples
        if (!empty($filterData->status)) {
            $query->where('status', $filterData->status);
        }

        if (!empty($filterData->action)) {
            $query->where('action', $filterData->action);
        }

        if (!empty($filterData->warehouse_uuid)) {
            $query->where('warehouse_uuid', $filterData->warehouse_uuid);
        }

        // 🔹 Recherche globale
        if (!empty($filterData->search)) {
            $search = trim($filterData->search);

            $query->where(function ($q) use ($search) {
                $q->where('uuid', 'like', "%{$search}%")
                    ->orWhere('status', 'like', "%{$search}%")
                    ->orWhere('notes', 'like', "%{$search}%")
                    ->orWhere('comment', 'like', "%{$search}%")
                    ->orWhere('action', 'like', "%{$search}%")
                    ->orWhere('reference', 'like', "%{$search}%")

                    // Entrepôt
                    ->orWhereHas('warehouse', fn ($qw) =>
                    $qw->where('name', 'like', "%{$search}%")
                        ->orWhere('ref', 'like', "%{$search}%")
                        ->orWhere('address', 'like', "%{$search}%")
                        ->orWhere('stock_type', 'like', "%{$search}%")
                    )

                    // Utilisateurs
                    ->orWhereHas('creator', fn ($qu) =>
                    $qu->where('nom_utilisateur', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('login', 'like', "%{$search}%")
                    )
                    ->orWhereHas('updater', fn ($qu) =>
                    $qu->where('nom_utilisateur', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('login', 'like', "%{$search}%")
                    )
                    ->orWhereHas('validator', fn ($qu) =>
                    $qu->where('nom_utilisateur', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('login', 'like', "%{$search}%")
                    )

                    // Produits
                    ->orWhereHas('items.product', fn ($qp) =>
                    $qp->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                    );
            });
        }
        return $paginate
            ? $query->paginate(perPage: $filterData->limit, page: $filterData->page)
            : $query;
    }


}
/**
 * Retourne la requête ou la collection paginée des bons de commande filtrés
 *
 * @param \App\DTO\WarehouseFilterData $filter
 * @param bool $paginate
 * @return Builder|LengthAwarePaginator
 */
if (!function_exists('warehouse_filter')) {

    function warehouse_filter(\App\DTO\WarehouseFilterData $filterData, bool $paginate = true): Builder|LengthAwarePaginator
    {
        $search = $filterData->search;
        $auth   = $filterData->auth;

        $query = Warehouse::with(['creator', 'updater', 'natures', 'managers'])
            ->when(!$auth->hasRole('SUPER_ADMIN'), function (Builder $q) use ($auth) {
                $q->whereHas('managers', function ($sub) use ($auth) {
                    $sub->where('user_id', $auth->id);
                });
            })
            ->when($search, function (Builder $q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('ref', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%")
                        ->orWhere('stock_type', 'like', "%{$search}%")
                        ->orWhere('address', 'like', "%{$search}%")
                        ->orWhere('total_stock', 'like', "%{$search}%");
                });
            })
            ->orderBy('created_at', 'desc');

        return $paginate
            ? $query->paginate(perPage: $filterData->limit, page: $filterData->page)
            : $query;
    }
}

if (!function_exists('purchase_order_filter')) {
    /**
     * Retourne la requête ou la collection paginée des bons de commande filtrés
     *
     * @param PurchaseOrderFilterData $filter
     * @param bool $paginate
     * @return Builder|LengthAwarePaginator
     */
    function purchase_order_filter(PurchaseOrderFilterData $filterData, bool $paginate = true): Builder|LengthAwarePaginator
    {
        $search = $filterData->search;
        $limit = $filterData->limit;
        $page  = $filterData->page;

        $query = PurchaseOrder::with([
            'items.product',
            'warehouseTo.managers',
            'warehouseFrom.managers',
            'creator',
            'updater',
            'approver',
            'children',
            'parent'
        ])
            ->when($search, function (Builder $query) use ($search) {
                $query->where(function (Builder $query) use ($search) {
                    $query->whereLike('uuid', "%{$search}%")
                        ->orWhereLike('type', "%{$search}%")
                        ->orWhereLike('status', "%{$search}%")
                        ->orWhereLike('notes', "%{$search}%")
                        ->orWhereLike('created_at', "%{$search}%")
                        ->orWhereLike('updated_at', "%{$search}%")
                        ->orWhereLike('reference', "%{$search}%");
                });
            })
            ->when($filterData->type, fn($query) => $query->where('type', $filterData->type))
            ->when($filterData->status, fn($query) => $query->where('status', $filterData->status))
            ->when($filterData->start_date && $filterData->end_date, function (Builder $query) use ($filterData) {
                $query->whereBetween('created_at', [
                    Carbon::parse($filterData->start_date)->startOfDay(),
                    Carbon::parse($filterData->end_date)->endOfDay(),
                ]);
            })
            ->when($filterData->auth, function (Builder $query) use ($filterData) {
                if ($filterData->auth->hasRole('GESTIONNAIRE_STOCK')) {
                    $query->where('transfered_by', $filterData->auth->id);
                } elseif (!$filterData->auth->hasRole('SUPER_ADMIN')) {
                    $query->where('created_by', $filterData->auth->id);
                }
            })
            ->orderBy('created_at', 'desc');

        return $paginate
            ? $query->paginate(perPage: $filterData->limit, page: $filterData->page)
            : $query;
    }
}

    /**
     * Retourne la requête ou la collection paginée des bons de approvisionnements filtrés
     *
     * @param SupplyFilterData $filter
     * @param bool $paginate
     * @return Builder|LengthAwarePaginator
     */
    function supply_filter(SupplyFilterData $filterData, bool $paginate = true): Builder|LengthAwarePaginator
    {
        $search = $filterData->search;
        $limit = $filterData->limit;
        $page  = $filterData->page;

        $query = Supply::with([
            'items.product',
            'purchaseOrder.items',
            'creator',
            'updater',
            'validator',
            'supplier',
            'warehouse',
            'medias',
            'cancelled'
        ])
            ->when($filterData->type, fn(Builder $q) => $q->where('type', $filterData->type))
            ->when($filterData->status, fn(Builder $q) => $q->where('status', $filterData->status))
            ->when($filterData->start_date && $filterData->end_date, function (Builder $query) use ($filterData) {
                $query->whereBetween('created_at', [
                    Carbon::parse($filterData->start_date)->startOfDay(),
                    Carbon::parse($filterData->end_date)->endOfDay(),
                ]);
            })
            ->when($search, function (Builder $q) use ($search) {
                $q->where(function (Builder $q2) use ($search) {
                    $q2->where('reference', 'like', "%{$search}%")
                        ->orWhere('uuid', 'like', "%{$search}%")
                        ->orWhere('status', 'like', "%{$search}%")
                        ->orWhere('notes', 'like', "%{$search}%")
                        ->orWhere('purchase_order_uuid', 'like', "%{$search}%")
                        ->orWhere('warehouse_uuid', 'like', "%{$search}%")
                        ->orWhere('supplier_uuid', 'like', "%{$search}%")
                        ->orWhereHas('supplier', fn($qs) =>
                        $qs->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('company_name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%")
                            ->orWhere('phone_number', 'like', "%{$search}%")
                            ->orWhere('address', 'like', "%{$search}%")
                        )
                        ->orWhereHas('purchaseOrder', fn($qpo) =>
                        $qpo->where('reference', 'like', "%{$search}%")
                            ->orWhere('type', 'like', "%{$search}%")
                            ->orWhere('status', 'like', "%{$search}%")
                            ->orWhere('warehouse_from', 'like', "%{$search}%")
                            ->orWhere('warehouse_to', 'like', "%{$search}%")
                            ->orWhere('supplier_uuid', 'like', "%{$search}%")
                        )
                        ->orWhereHas('warehouse', fn($qw) =>
                        $qw->where('ref', 'like', "%{$search}%")
                            ->orWhere('name', 'like', "%{$search}%")
                            ->orWhere('stock_type', 'like', "%{$search}%")
                            ->orWhere('address', 'like', "%{$search}%")
                        )
                        ->orWhereHas('creator', fn($qc) =>
                        $qc->where('nom_utilisateur', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%")
                        )
                        ->orWhereHas('validator', fn($qv) =>
                        $qv->where('nom_utilisateur', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%")
                        )
                        ->orWhereHas('items.product', fn($qp) =>
                        $qp->where('name', 'like', "%{$search}%")
                            ->orWhere('code', 'like', "%{$search}%")
                            ->orWhere('description', 'like', "%{$search}%")
                        );
                });
            })
            ->when($filterData->auth, function (Builder $q) use ($filterData) {
                if ($filterData->auth->hasRole('SUPER_ADMIN')) {
                    // SUPER_ADMIN voit tout → aucun filtre
                } elseif ($filterData->auth->hasRole('GESTIONNAIRE_STOCK')) {
                    $q->where('created_by', $filterData->auth->id);
                } else {
                    $q->where(function ($q2) use ($filterData) {
                        $q2->where('created_by', $filterData->auth->id)
                            ->orWhereHas('purchaseOrder', fn($qpo) => $qpo->where('created_by', $filterData->auth->id));
                    });
                }
            })
            ->orderBy('created_at', 'desc');

        return $paginate
            ? $query->paginate(perPage: $filterData->limit, page: $filterData->page)
            : $query;
    }



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
    $bootstrapContent .= "
    @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap');
    body {
        font-family: 'Poppins', sans-serif;
    }
    ";
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
