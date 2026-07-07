<?php

use App\DTO\OrderMenuRestaurantFilterData;
use App\DTO\PurchaseOrderFilterData;
use App\DTO\SupplyFilterData;
use App\Models\Medias;
use App\Models\Passation;
use App\Models\PurchaseOrder;
use App\Models\StockAdjustment;
use App\Models\StockDeduction;
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


if (!function_exists('filter_orders_menu_restaurants')) {
    /**
     * Filtre les commandes de restaurant et retourne soit la requête, soit la pagination.
     *
     * @param Builder $query
     * @param OrderMenuRestaurantFilterData $data
     * @param bool $paginate
     * @return \Illuminate\Database\Eloquent\Builder|\Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    function filter_orders_menu_restaurants(Builder $query, OrderMenuRestaurantFilterData $data, bool $paginate = true)
    {
        $dateFilterApplied = false;

        if (!empty($data->order_menu_restaurant_date)) {
            $query->where('order_menu_restaurant_date', $data->order_menu_restaurant_date);
            $dateFilterApplied = true;
        }
        if (!empty($data->restaurant_table_uuid)) {
            $query->where('restaurant_table_uuid', $data->restaurant_table_uuid);
        }
        if (!empty($data->menu_restaurant_uuid)) {
            $query->where('menu_restaurant_uuid', $data->menu_restaurant_uuid);
        }
        if (!empty($data->restaurant_room_uuid)) {
            $query->where('restaurant_room_uuid', $data->restaurant_room_uuid);
        }
        if (!empty($data->partners_restaurant_uuid)) {
            $query->where('partners_restaurant_uuid', $data->partners_restaurant_uuid);
        }
        if (!empty($data->consumption_type)) {
            $query->where('consumption_type', $data->consumption_type);
        }
        if (!empty($data->type_clients_for_payment)) {
            $query->where('type_clients_for_payment', $data->type_clients_for_payment);
        }
        if (!empty($data->status)) {
            $query->where('status', $data->status);
        }

        // 2. Gestion de la plage de dates
        if (!empty($data->start_date) && !empty($data->end_date)) {
            $start_date = Carbon::parse($data->start_date)->startOfDay();
            $end_date = Carbon::parse($data->end_date)->endOfDay();

            $query->whereBetween('created_at', [$start_date, $end_date]);
            $dateFilterApplied = true;
        }

        if (!$dateFilterApplied) {
            $query->whereDate('created_at', Carbon::today());
        }


        $auth = $data->auth ?? auth()->user();

        if ($auth && !$auth->hasRole('SUPER_ADMIN') && !$auth->can('view_kitchen_and_bar_orders')) {
            $roleIds = $auth->roles->pluck('id');

            $query->where(function ($q) use ($auth, $roleIds) {
                $hasVisibilityPermission = false;

                if ($auth->can('view_role_related_data')) {
                    $q->whereHas('creator.roles', function ($qr) use ($roleIds) {
                        $qr->whereIn('roles.id', $roleIds);
                    });
                    $hasVisibilityPermission = true;
                }

                if ($auth->can('view_kitchen_orders')) {
                    $hasVisibilityPermission ? $q->orWhereNotNull('kitchen_user_id') : $q->whereNotNull('kitchen_user_id');
                    $hasVisibilityPermission = true;
                }

                if ($auth->can('view_bar_orders')) {
                    $hasVisibilityPermission ? $q->orWhereNotNull('bar_user_id') : $q->whereNotNull('bar_user_id');
                    $hasVisibilityPermission = true;
                }

                if (!$hasVisibilityPermission) {
                    $q->where('created_by', $auth->id);
                }
            });
        }

        if ($search = trim($data->search)) {
            $query->where(function ($q) use ($search) {
                $q->where('uuid', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('status', 'like', "%{$search}%")
                    ->orWhere('unit_price', 'like', "%{$search}%")
                    ->orWhere('total_price', 'like', "%{$search}%")
                    ->orWhere('is_for_sale_free', 'like', "%{$search}%")
                    ->orWhere('consumption_type', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('reason_cancel', 'like', "%{$search}%")
                    ->orWhere('validated_at', 'like', "%{$search}%")
                    ->orWhere('cancelled_at', 'like', "%{$search}%")
                    ->orWhere('restaurant_table_uuid', 'like', "%{$search}%")
                    ->orWhere('created_by', 'like', "%{$search}%")
                    ->orWhere('type_clients_for_payment', 'like', "%{$search}%")
                    ->orWhere('order_menu_restaurant_date', 'like', "%{$search}%")
                    ->orWhere('remise', 'like', "%{$search}%")
                    ->orWhere('partners_restaurant_uuid', 'like', "%{$search}%")
                    ->orWhere('warehouse_uuid', 'like', "%{$search}%")
                    ->orWhere('restaurant_room_uuid', 'like', "%{$search}%")
                    ->orWhere('menu_restaurant_uuid', 'like', "%{$search}%")
                    ->orWhere('quantity', 'like', "%{$search}%");
            });
        }

        return $paginate
            ? $query->latest()->paginate($data->limit, ['*'], 'page', $data->page)
            : $query;
    }

}


if (!function_exists('filter_stocks_deductions')) {
    /**
     * Filtrer les déductions de stocks
     *
     * @param \App\DTO\DeductionsStocksFiliterData $filterData
     * @param bool $paginate
     * @return Builder|LengthAwarePaginator
     */
    function filter_stocks_deductions(\App\DTO\DeductionsStocksFiliterData $filterData, bool $paginate = true): Builder|LengthAwarePaginator
    {
        $query = StockDeduction::with([
            'items.product',
            'warehouse',
            'creator',
            'updater',
            'validator',
            'canceler'
        ]);

        // Filtre status
        if ($filterData->status) {
            $query->where('status', $filterData->status);
        }

        // Filtre entrepôt
        if ($filterData->warehouse_uuid) {
            $query->where('warehouse_uuid', $filterData->warehouse_uuid);
        }

        // Filtre date
        if ($filterData->start_date && $filterData->end_date) {
            $start = \Illuminate\Support\Carbon::parse($filterData->start_date)->startOfDay();
            $end   = \Illuminate\Support\Carbon::parse($filterData->end_date)->endOfDay();
            $query->whereBetween('created_at', [$start, $end]);
        }

        // Filtre super admin / user normal
        if ($filterData->auth && !$filterData->auth->hasRole('SUPER_ADMIN')) {
            $query->where('created_by', $filterData->auth->id);
        }

        // Filtre recherche globale
        if ($search = trim($filterData->search ?? '')) {
            $query->where(function ($q) use ($search) {
                $q->where('uuid', 'like', "%{$search}%")
                    ->orWhere('status', 'like', "%{$search}%")
                    ->orWhere('comment', 'like', "%{$search}%")
                    ->orWhere('reason_of_cancel', 'like', "%{$search}%")
                    ->orWhere('reference', 'like', "%{$search}%")
                    ->orWhereHas('warehouse', fn($qw) => $qw->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('creator', fn($qc) => $qc->where('nom_utilisateur', 'like', "%{$search}%"))
                    ->orWhereHas('updater', fn($qu) => $qu->where('nom_utilisateur', 'like', "%{$search}%"))
                    ->orWhereHas('validator', fn($qv) => $qv->where('nom_utilisateur', 'like', "%{$search}%"))
                    ->orWhereHas('items.product', fn($qp) => $qp->where('name', 'like', "%{$search}%"));
            });
        }

        // Retourne Builder ou Paginator
        return $paginate
            ? $query->latest()->paginate($filterData->limit, ['*'], 'page', $filterData->page)
            : $query;
    }
}


if (!function_exists('filter_passations')) {

    /**
     * Filtrer les passations pour le module stocks / régularisation
     *
     * @param \App\DTO\PassationsFilterData $filterData
     * @param bool $paginate
     * @return Builder|LengthAwarePaginator
     */
    function filter_passations(\App\DTO\PassationsFilterData $filterData, bool $paginate = true): Builder|LengthAwarePaginator
    {
        $auth = auth()->user();

        $query = Passation::with([
            'agentFrom',
            'agentTo',
            'warehouse',
            'creator',
            'updater',
            'validator',
            'rejector',
            'cancellor',
            'managers',
            'items.product'
        ]);

        // 🔹 Filtre status
        if (!empty($filterData->status)) {
            $query->where('status', $filterData->status);
        }

        // 🔹 Filtre agent_from
        if (!empty($filterData->agent_from_id)) {
            $query->where('agent_from_id', $filterData->agent_from_id);
        }

        // 🔹 Filtre entrepôt
        if (!empty($filterData->warehouse_uuid)) {
            $query->where('warehouse_uuid', $filterData->warehouse_uuid);
        }

        // 🔹 Filtre dates
        if (!empty($filterData->start_date) && !empty($filterData->end_date)) {
            $start = Carbon::parse($filterData->start_date)->startOfDay();
            $end = Carbon::parse($filterData->end_date)->endOfDay();
            $query->whereBetween('created_at', [$start, $end]);
        }

        // 🔹 Permissions : voir uniquement ses passations si pas SUPER_ADMIN ou view_all_passations
        if (!$auth->hasRole('SUPER_ADMIN') && !$auth->can('view_all_passations')) {
            $query->where(function ($q) use ($auth) {
                $q->where('created_by', $auth->id)
                    ->orWhereHas('managers', function ($q2) use ($auth) {
                        $q2->where('manager_id', $auth->id);
                    });
            });
        }

        // 🔹 Recherche globale
        if (!empty($filterData->search)) {
            $search = trim($filterData->search);
            $query->where(function ($q) use ($search) {
                $q->where('uuid', 'like', "%{$search}%")
                    ->orWhere('status', 'like', "%{$search}%")
                    ->orWhere('reference', 'like', "%{$search}%")
                    ->orWhereHas('agentFrom', fn($qf) => $qf->where('login', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('nom_utilisateur', 'like', "%{$search}%")
                        ->orWhere('prenom', 'like', "%{$search}%")
                        ->orWhere('id', 'like', "%{$search}%"))
                    ->orWhereHas('agentTo', fn($qf) => $qf->where('login', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('nom_utilisateur', 'like', "%{$search}%")
                        ->orWhere('prenom', 'like', "%{$search}%")
                        ->orWhere('id', 'like', "%{$search}%"))
                    ->orWhereHas('creator', fn($qc) => $qc->where('nom_utilisateur', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%"))
                    ->orWhereHas('items.product', fn($qp) => $qp->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhere('uuid', 'like', "%{$search}%"));
            });
        }

        return $paginate ? $query->latest()->paginate($filterData->per_page ?? 25, ['*'], 'page', $filterData->page ?? 1)
            : $query;
    }
}

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

        if (!empty($filterData->start_date) && !empty($filterData->end_date)) {
            $start = Carbon::parse($filterData->start_date)->startOfDay();
            $end = Carbon::parse($filterData->end_date)->endOfDay();
            $query->whereBetween('created_at', [$start, $end]);
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


function save_browser_shot_pdf(
    string $view,
    array $data,
    string $folderPath,
    string $path,
           $format = 'a4', // ◄ Supprime le type string ici pour accepter array OU string
    string $direction = '',
    string $header = '',
    string $footer = '',
    array $margins = [0, 0, 0, 0]
): void {
    $bootstrapPath = public_path('assets/bootstrap/css/bootstrap.min.css');
    $bootstrapContent = file_get_contents($bootstrapPath);
    $data = array_merge($data, ['bootstrap' => $bootstrapContent]);

    $folderPath = public_path($folderPath);
    if (!File::exists($folderPath)) {
        File::makeDirectory($folderPath, 0755, true);
    }

    $html = view($view, $data)->render();

    $browserShot = Browsershot::html($html)
        ->margins($margins[0], $margins[1], $margins[2], $margins[3])
        ->timeout(120)
        ->waitUntilNetworkIdle()
        ->printBackground();

    // ◄ AJUSTEMENT ICI : Si c'est un tableau [largeur, hauteur], on utilise paperSize
    if (is_array($format)) {
        $browserShot->paperSize($format[0], $format[1], 'mm');
    } else {
        $browserShot->format($format);
    }

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
