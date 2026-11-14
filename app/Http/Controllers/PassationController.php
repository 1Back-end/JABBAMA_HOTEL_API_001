<?php

namespace App\Http\Controllers;

use App\DTO\PassationsFilterData;
use App\DTO\StockAdjustmentFilterData;
use App\Enums\PassationStatus;
use App\Exports\PassationsStocksExport;
use App\Exports\StockAdjustmentsExport;
use App\Models\Passation;
use App\Models\PassationItem;
use App\Models\PdfDocument;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\Warehouse;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Mockery\Generator\StringManipulation\Pass\Pass;

/**
 * @permission_category Gestion des passations de stocks
 * @permission_module Gestion des stocks
 * @code_module STOCK
 */
class PassationController extends Controller
{

    /**
     * Display a listing of the resource.
     * @permission PassationController::index
     * @code STOCK
     * @permission_desc Afficher la liste des passations de stocks
     */
    public function index(Request $request)
    {
        $auth = auth()->user();
        $roleIds = $auth->roles->pluck('id');
        $perPage = $request->input('limit', 25);
        $page = $request->input('page', 1);

        $query = Passation::with([
            'agentFrom',
            'agentTo',
            'warehouse',
            'creator:id,nom_utilisateur',
            'updater:id,nom_utilisateur',
            'validator:id,nom_utilisateur',
            'rejector:id,nom_utilisateur',
            'cancellor:id,nom_utilisateur',
            'managers'
        ]);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('agent_from_id')) {
            $query->where('agent_from_id', $request->input('agent_from_id'));
        }

        if ($request->filled('warehouse_uuid')) {
            $query->where('warehouse_uuid', $request->warehouse_uuid);
        }

        if ($request->filled('start_date') && $request->filled('end_date')) {
            $start_date = \Illuminate\Support\Carbon::parse($request->input('start_date'))->startOfDay();
            $end_date = \Illuminate\Support\Carbon::parse($request->input('end_date'))->endOfDay();

            $query->whereBetween('created_at', [$start_date, $end_date]);
        }

        if (!$auth->is_super_admin) {
            $query->whereHas('warehouse.managers', function($qw) use ($auth) {
                $qw->where('warehouse_managers.user_id', $auth->id)
                    ->whereNull('warehouse_managers.deleted_at');
            });

            $query->where(function($q) use ($auth, $roleIds) {
                if ($auth->can('view_role_related_data')) {
                    $q->whereHas('creator.roles', fn($qr) => $qr->whereIn('roles.id', $roleIds));
                } else {
                    $q->where('created_by', $auth->id)
                        ->orWhereHas('managers', fn($qm) => $qm->where('manager_id', $auth->id));
                }
            });
        }

        if ($search = trim($request->input('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('uuid', 'like', "%{$search}%")
                    ->orWhere('status', 'like', "%{$search}%")
                    ->orWhere('reference', 'like', "%{$search}%")

                    ->orWhereHas('agentFrom', function ($qw) use ($search) {
                        $qw->where('login', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%")
                            ->orWhere('nom_utilisateur', 'like', "%{$search}%")
                            ->orWhere('prenom', 'like', "%{$search}%")
                            ->orWhere('id', 'like', "%{$search}%");
                    })
                    ->orWhereHas('agentTo', function ($qf) use ($search) {
                        $qf->where('login', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%")
                            ->orWhere('nom_utilisateur', 'like', "%{$search}%")
                            ->orWhere('prenom', 'like', "%{$search}%")
                            ->orWhere('id', 'like', "%{$search}%");
                    })

                    ->orWhereHas('creator', function ($qc) use ($search) {
                        $qc->where('nom_utilisateur', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    })

                    ->orWhereHas('items.product', function ($qp) use ($search) {
                        $qp->where('name', 'like', "%{$search}%")
                            ->orWhere('code', 'like', "%{$search}%")
                            ->orWhere('description', 'like', "%{$search}%")
                            ->orWhere('uuid', 'like', "%{$search}%");
                    });
            });
        }

        $data = $query->latest()->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data'         => $data->items(),
            'current_page' => $data->currentPage(),
            'last_page'    => $data->lastPage(),
            'total'        => $data->total(),
        ]);
    }

    private function sendPassationNotifications(
        string $passationUuid,
        int $userId,
        string $status,
        string $message,
        string $target
    ) {
        $commonData = [
            'passation_uuid' => $passationUuid,
            'status' => $status,
            'message' => $message,
            'target' => $target,
            'user_id' => $userId,
            'is_read' => false,
            'created_by' => auth()->id(),
        ];

        \App\Models\PassationNotification::create($commonData);
        \App\Models\PassationDecisionnelle::create($commonData);
    }


    /**
     * Display a listing of the resource.
     * @permission PassationController::store
     * @code STOCK
     * @permission_desc Créer une passation de stocks
     */
    public function store(Request $request)
    {
        $auth = auth()->user();

        // Validation
        $validated = $request->validate([
            'warehouse_uuid' => 'required|exists:warehouses,uuid',
        ]);

        $warehouse = Warehouse::where('uuid', $validated['warehouse_uuid'])->firstOrFail();
        $managers = $warehouse->managers;

        if ($managers->isEmpty()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Aucun manager trouvé pour cet entrepôt.'
            ], 404);
        }

        $products = $warehouse->products()
            ->wherePivot('is_active', true)
            ->get();
        $totalStock = $products->sum(fn ($p) => $p->pivot->quantity);

        DB::beginTransaction();
        try {

            $passation = Passation::create([
                'agent_from_id' => $auth->id,
                'warehouse_uuid' => $warehouse->uuid,
                'status' => 'pending',
                'quantity_sent' => $totalStock,
                'created_by' => $auth->id,
            ]);

            foreach ($products as $product) {
                PassationItem::create([
                    'passation_uuid' => $passation->uuid,
                    'product_uuid' => $product->uuid,
                    'quantity_sent' => $product->pivot->quantity,
                    'quantity_counted' => 0,
                    'difference' => 0,
                    'created_by' => $auth->id,
                    'status' => 'pending',
                ]);
            }

            foreach ($managers as $manager) {
                if ($manager->id == $auth->id) {
                    continue;
                }

                DB::table('passation_managers')->insert([
                    'uuid' => \Str::uuid(),
                    'passation_uuid' => $passation->uuid,
                    'manager_id' => $manager->id,
                    'status' => 'pending',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $this->sendPassationNotifications(
                passationUuid: $passation->uuid,
                userId: $manager->id,
                status: 'pending',
                message: "Une nouvelle passation de stock a été initiée pour l'entrepôt {$warehouse->name} et nécessite votre validation.",
                target: 'manager_dashboard'
            );

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Passation créée et assignée à tous les managers.',
                'passation' => $passation->load('items', 'managers', 'warehouse'),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la création de la passation.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display a listing of the resource.
     * @permission PassationController::update
     * @code STOCK
     * @permission_desc Modifier une passation de stocks
     */
    public function update(Request $request, string $uuid)
    {
        $auth = auth()->user();

        $validated = $request->validate([
            'warehouse_uuid' => 'required|exists:warehouses,uuid',
        ]);

        $passation = Passation::where('uuid', $uuid)->firstOrFail();

        $warehouse = Warehouse::where('uuid', $validated['warehouse_uuid'])->firstOrFail();

        $products = $warehouse->products()
            ->wherePivot('is_active', true)
            ->get();

        $totalStock = $products->sum(fn ($p) => $p->pivot->quantity);

        DB::beginTransaction();
        try {

            $passation->update([
                'warehouse_uuid' => $warehouse->uuid,
                'quantity_sent' => $totalStock,
                'updated_by' => $auth->id,
                'status' => 'pending',
            ]);

            PassationItem::where('passation_uuid', $passation->uuid)->delete();

            foreach ($products as $product) {
                PassationItem::create([
                    'passation_uuid' => $passation->uuid,
                    'product_uuid' => $product->uuid,
                    'quantity_sent' => $product->pivot->quantity,
                    'quantity_counted' => 0,
                    'difference' => 0,
                    'status' => 'pending',
                    'created_by' => $auth->id,
                ]);
            }

            $this->sendPassationNotifications(
                passationUuid: $passation->uuid,
                userId: $auth->id,
                status: 'pending',
                message: "La passation de stock pour l'entrepôt {$warehouse->name} a été mise à jour et nécessite votre attention.",
                target: 'manager_dashboard'
            );

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Passation mise à jour avec succès.',
                'passation' => $passation->load('items', 'warehouse'),
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la mise à jour de la passation.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display a listing of the resource.
     * @permission PassationController::show
     * @code STOCK
     * @permission_desc Afficher les détails d'une passation de stocks
     */
    public function show(Request $request, $uuid)
    {
        $auth = auth()->user();
        $perPage = $request->input('limit', 10);
        $page = $request->input('page', 1);
        $search = trim($request->input('search'));

        $passation = Passation::with(['agentFrom', 'agentTo', 'warehouse', 'creator', 'updater'])
            ->where('uuid', $uuid)
            ->firstOrFail();

        $itemsQuery = $passation->items()
            ->with('product')
            ->join('produits', 'passation_items.product_uuid', '=', 'produits.uuid')
            ->orderBy('produits.name', 'asc')
            ->select('passation_items.*')
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('passation_items.quantity_sent', 'like', "%{$search}%")
                        ->orWhere('passation_items.quantity_counted', 'like', "%{$search}%")
                        ->orWhere('passation_items.difference', 'like', "%{$search}%")
                        ->orWhereHas('product', function ($qp) use ($search) {
                            $qp->where('name', 'like', "%{$search}%")
                                ->orWhere('code', 'like', "%{$search}%");
                        });
                });
            });

        $paginated = $itemsQuery->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data'         => $paginated->items(),
            'current_page' => $paginated->currentPage(),
            'last_page'    => $paginated->lastPage(),
            'total'        => $paginated->total(),
            'passation'    => $passation,
        ]);
    }



    /**
     * Display a listing of the resource.
     * @permission PassationController::validate_passations
     * @code STOCK
     * @permission_desc Valider une passation de stocks
     */
    public function validate_passations(Request $request, string $uuid)
    {
        $auth = auth()->user();

        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.uuid' => 'required|exists:passation_items,uuid',
            'items.*.quantity_counted' => 'required|integer|min:0',
        ], [
            'items.required' => 'Les items sont obligatoires.',
            'items.*.quantity_counted.required' => 'La quantité comptée est obligatoire.',
        ]);

        DB::beginTransaction();

        try {
            $passation = Passation::with('items.product')
                ->where('uuid', $uuid)
                ->firstOrFail();

            $hasGap = false;

            foreach ($validated['items'] as $itemData) {
                $item = $passation->items
                    ->where('uuid', $itemData['uuid'])
                    ->first();

                if (!$item) {
                    continue;
                }

                $qteSent = (int) $item->quantity_sent;
                $qteCounted = (int) $itemData['quantity_counted'];

                if ($qteCounted > $qteSent) {
                    DB::rollBack();
                    return response()->json([
                        'status' => 'error',
                        'message' => "La quantité comptée pour l'article {$item->product?->name} ne peut pas être supérieure à la quantité envoyée.",
                    ], 400);
                }

                $difference = $qteSent - $qteCounted;

                if ($difference > 0) {
                    $hasGap = true;
                }

                $itemStatus = $difference === 0
                    ? PassationStatus::NO_GAP->value
                    : PassationStatus::IN_DISCUSS->value;

                $item->update([
                    'quantity_counted' => $qteCounted,
                    'difference' => $difference,
                    'status' => $itemStatus,
                    'validated_at' => now(),
                    'validated_by' => $auth->id,
                    'updated_by' => $auth->id,
                ]);
            }

            $passationStatus = 'in_discuss';

            $passation->update([
                'status' => $passationStatus,
                'validated_at' => now(),
                'validated_by' => $auth->id,
                'updated_by' => $auth->id,
                'reason_validated' => 'Validation par comptage',
            ]);

            $warehouseName = $passation->warehouse?->name ?? '';
            $message = $hasGap
                ? "Validation effectuée avec écarts : le contrôle d'inventaire de l'entrepôt {$warehouseName} a été soumis par {$auth->nom_utilisateur}. Une vérification est requise."
                : "Validation confirmée sans écart : le comptage de l'entrepôt {$warehouseName} a été approuvé par {$auth->nom_utilisateur}.";

            $this->sendPassationNotifications(
                passationUuid: $passation->uuid,
                userId: $passation->created_by ?? $auth->id,
                status: 'in_discuss',
                message: $message,
                target: 'manager_dashboard'
            );

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Passation validée avec succès.',
                'passation' => $passation->load(
                    'items.product',
                    'agentFrom',
                    'agentTo',
                    'warehouse'
                ),
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la validation de la passation.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }


    /**
     * Display a listing of the resource.
     * @permission PassationController::update_passation_validation
     * @code STOCK
     * @permission_desc Modifier une passation de stocks en discussion
     */
    public function update_passation_validation(Request $request, string $uuid)
    {
        $auth = auth()->user();

        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.uuid' => 'required|exists:passation_items,uuid',
            'items.*.quantity_counted' => 'required|integer|min:0',
        ], [
            'items.required' => 'Les items sont obligatoires.',
            'items.*.quantity_counted.required' => 'La quantité comptée est obligatoire.',
        ]);

        DB::beginTransaction();

        try {
            $passation = Passation::with(['items.product', 'warehouse'])
                ->where('uuid', $uuid)
                ->firstOrFail();

            $hasGap = false;

            foreach ($validated['items'] as $itemData) {
                $item = $passation->items
                    ->where('uuid', $itemData['uuid'])
                    ->first();

                if (!$item) continue;

                $qteSent = (int) $item->quantity_sent;
                $qteCounted = (int) $itemData['quantity_counted'];

                if ($qteCounted > $qteSent) {
                    DB::rollBack();
                    return response()->json([
                        'status' => 'error',
                        'message' => "La quantité comptée pour l'article {$item->product?->name} ne peut pas être supérieure à la quantité envoyée.",
                    ], 400);
                }

                $difference = $qteSent - $qteCounted;

                if ($difference > 0) {
                    $hasGap = true;
                }

                $itemStatus = $difference === 0
                    ? PassationStatus::NO_GAP->value
                    : PassationStatus::IN_DISCUSS->value;

                $item->update([
                    'quantity_counted' => $qteCounted,
                    'difference' => $difference,
                    'status' => $itemStatus,
                    'validated_at' => now(),
                    'validated_by' => $auth->id,
                    'updated_by' => $auth->id,
                ]);
            }

            $passationStatus = 'in_discuss';

            $passation->update([
                'status' => 'in_discuss',
                'validated_at' => now(),
                'validated_by' => $auth->id,
                'updated_by' => $auth->id,
                'reason_validated' => 'Validation modifiée par comptage',
            ]);

            $warehouseName = $passation->warehouse?->name ?? 'inconnu';

            $message = $hasGap
                ? "Mise à jour de validation avec écarts : le contrôle de l'entrepôt {$warehouseName} a été modifié par {$auth->nom_utilisateur}."
                : "Mise à jour de validation sans écart : le comptage de l'entrepôt {$warehouseName} a été validé par {$auth->nom_utilisateur}.";

            $this->sendPassationNotifications(
                passationUuid: $passation->uuid,
                userId: $passation->created_by ?? $auth->id,
                status: 'in_discuss',
                message: $message,
                target: 'manager_dashboard'
            );

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Passation mise à jour avec succès.',
                'passation' => $passation->load(
                    'items.product',
                    'agentFrom',
                    'agentTo',
                    'warehouse'
                ),
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la mise à jour de la passation.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }


    /**
     * Display a listing of the resource.
     * @permission PassationController::cancel_passations
     * @code STOCK
     * @permission_desc Annuler une passation de stocks
     */
    public function cancel_passations(Request $request, string $uuid)
    {
        $auth = auth()->user();

        $validated = $request->validate([
            'reason_cancelled' => 'required|string|min:3',
        ], [
            'reason_cancelled.required' => 'Le motif du rejet est obligatoire.',
        ]);

        DB::beginTransaction();

        try {
            $passation = Passation::with('warehouse')->where('uuid', $uuid)->firstOrFail();

            $passation->update([
                'status' => PassationStatus::CANCEL->value,
                'updated_by' => $auth->id,
                'cancelled_by' => $auth->id,
                'reason_cancelled' => $validated['reason_cancelled'],
                'cancelled_at' => now(),
            ]);

            $warehouseName = $passation->warehouse?->name ?? 'inconnu';

            $this->sendPassationNotifications(
                passationUuid: $passation->uuid,
                userId: $passation->created_by ?? $auth->id,
                status: PassationStatus::CANCEL->value,
                message: "Passation annulée : la procédure pour l'entrepôt {$warehouseName} a été annulée par {$auth->nom_utilisateur}. Motif : {$validated['reason_cancelled']}",
                target: 'manager_dashboard'
            );

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => "La passation de stocks a été annulée avec succès.",
                'passation' => $passation->load('warehouse', 'agentFrom', 'agentTo', 'items.product')
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => 'error',
                'message' => "Erreur lors de l'annulation de la passation.",
                'details' => $e->getMessage(),
            ], 500);
        }
    }


    /**
     * Display a listing of the resource.
     * @permission PassationController::reject_passations
     * @code STOCK
     * @permission_desc Rejetter une passation de stocks
     */
    public function reject_passations(Request $request, string $uuid)
    {
        $auth = auth()->user();

        $validated = $request->validate([
            'reason_reject' => 'required|string|min:3',
        ], [
            'reason_reject.required' => 'Le motif du rejet est obligatoire.',
        ]);

        DB::beginTransaction();

        try {
            $passation = Passation::with(['items', 'warehouse'])->where('uuid', $uuid)->firstOrFail();

            $passation->update([
                'status'        => PassationStatus::REJECTED->value,
                'rejected_at'   => now(),
                'rejected_by'   => $auth->id,
                'updated_by'    => $auth->id,
                'reason_reject' => $validated['reason_reject'],
            ]);

            foreach ($passation->items as $item) {
                $item->update([
                    'status' => 'pending',
                    'quantity_counted' => 0,
                    'difference' => 0,
                    'validated_at' => null,
                    'validated_by' => null,
                    'updated_by' => $auth->id,
                ]);
            }

            $warehouseName = $passation->warehouse?->name ?? 'inconnu';

            $this->sendPassationNotifications(
                passationUuid: $passation->uuid,
                userId: $passation->created_by ?? $auth->id,
                status: PassationStatus::REJECTED->value,
                message: "Passation rejetée : la procédure pour l'entrepôt {$warehouseName} a été rejetée par {$auth->nom_utilisateur}. Motif : {$validated['reason_reject']}",
                target: 'manager_dashboard'
            );

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Passation rejetée et tous les items remis à pending avec succès.',
                'passation' => $passation->load('agentFrom', 'agentTo', 'warehouse', 'items.product'),
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status'  => 'error',
                'message' => 'Erreur lors du rejet de la passation.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }


    /**
     * Display a listing of the resource.
     * @permission PassationController::destroy
     * @code STOCK
     * @permission_desc Supprimer une passation de stocks
     */
    public function destroy(Request $request, string $uuid)
    {
        $auth = auth()->user();

        $request->validate([
            'password' => 'required|string'
        ]);


        if (!Hash::check($request->password, $auth->password)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Mot de passe incorrect.'
            ], 422);
        }
        DB::beginTransaction();
        $passation = Passation::where('uuid', $uuid)->firstOrFail();

        $passation->delete();
        DB::commit();
        return response()->json([
            'status'  => 'success',
            'message' => "Suppression éffectuée avec succès"
        ],200);

    }

    /**
     * Display a listing of the resource.
     * @permission PassationController::print_passations
     * @code STOCK
     * @permission_desc Imprimer les fiches de passations de stocks en PDF
     */
    public function print_passations(Request $request, string $uuid)
    {
        $auth = auth()->user();
        try {
            DB::beginTransaction();
        $passations = Passation::with([
            'agentFrom',
            'agentTo',
            'warehouse',
            'creator',
            'updater',
            'validator',
            'rejector',
            'cancellor'
        ])
            ->findOrFail($uuid);

        $fileName   = strtoupper('DETAILS-PASSATIONS-' . now()->format('YmdHis') . '.pdf');
        $folderPath = 'storage/details-passations/' . $passations->uuid;
        $filePath   = $folderPath . '/' . $fileName;

        if (!is_dir($folderPath)) {
            if (!mkdir($folderPath, 0755, true) && !is_dir($folderPath)) {
                throw new \RuntimeException("Impossible de créer le répertoire : {$folderPath}");
            }
        }
        $data = ['passations' => $passations];

        $footer = 'pdfs.reports.factures.footer';

        save_browser_shot_pdf(
            view: 'pdfs.details-passations.details-passations',
            data: $data,
            folderPath: $folderPath,
            path: $filePath,
            margins: [10, 10, 10, 10],
            footer: $footer
        );

            DB::commit();

            if (!file_exists($filePath)) {
                return response()->json(['message' => "Le fichier PDF n'a pas été généré."], 500);
            }

            $pdf = PdfDocument::where('order_uuid', $passations->uuid)
                ->where('name', 'DETAILS-ORDERS')
                ->first();

            if ($pdf) {
                $pdf->update([
                    'path'       => $filePath,
                    'filename'   => $fileName,
                    'updated_by' => $auth->id,
                ]);
            }
            else {
                $pdf = PdfDocument::create([
                    'name'       => 'DETAILS-ORDERS',
                    'order_uuid' => $passations->uuid,
                    'disk'       => 'public',
                    'path'       => $filePath,
                    'filename'   => $fileName,
                    'mimetype'   => 'application/pdf',
                    'extension'  => 'pdf',
                    'created_by' => auth()->id(),
                ]);
            }


            $pdfContent = file_get_contents($filePath);
            $base64     = base64_encode($pdfContent);

            return response()->json([
                'data'     => $data,
                'base64'   => $base64,
                'url'      => $filePath,
                'filename' => $fileName,
                'document' => $pdf,
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();

            \Log::error("Erreur génération PDF commande : " . $e->getMessage());

            return response()->json([
                'status'  => 'error',
                'message' => "Erreur lors de la génération du fichier PDF.",
                'details' => $e->getMessage()
            ], 500);
        }


    }

    /**
     * Display a listing of the resource.
     * @permission PassationController::validate_passations_by_admin
     * @code STOCK
     * @permission_desc Valider une passation de stocks par le SUPER ADMIN
     */
    public function validate_passations_by_admin(Request $request, string $uuid)
    {
        $auth = auth()->user();

        $request->validate([
            'password' => 'required|string'
        ]);

        if (!Hash::check($request->password, $auth->password)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Mot de passe incorrect.'
            ], 422);
        }

        DB::beginTransaction();

        try {
            $passation = Passation::with(['items', 'warehouse'])
                ->where('uuid', $uuid)
                ->firstOrFail();

            $hasGap = $passation->items
                    ->where('difference', '>', 0)
                    ->count() > 0;

            $finalStatusEnum = $hasGap ? PassationStatus::WITH_GAP : PassationStatus::NO_GAP;

            $passation->update([
                'status'       => $finalStatusEnum->value,
                'approved_by'  => $auth->id,
                'approved_at'  => now(),
                'updated_by'   => $auth->id,
                'closed_at'    => now(),
            ]);

            $warehouseName = $passation->warehouse?->name ?? 'inconnu';

            $message = $hasGap
                ? "Validation définitive (avec écarts) : la passation de l'entrepôt {$warehouseName} a été approuvée par l'administration ({$auth->nom_utilisateur})."
                : "Validation définitive (sans écart) : la passation de l'entrepôt {$warehouseName} a été clôturée avec succès par {$auth->nom_utilisateur}.";

            $this->sendPassationNotifications(
                passationUuid: $passation->uuid,
                userId: $passation->created_by ?? $auth->id,
                status: $finalStatusEnum->value,
                message: $message,
                target: 'manager_dashboard'
            );

            DB::commit();

            return response()->json([
                'status'    => 'success',
                'message'   => 'Passation validée définitivement par le super administrateur.',
                'passation' => $passation->load(
                    'items.product',
                    'agentFrom',
                    'agentTo',
                    'warehouse'
                ),
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status'  => 'error',
                'message' => 'Erreur lors de la validation définitive de la passation.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }


    /**
     * Display a listing of the resource.
     * @permission PassationController::print_passations_by_agents_uuid
     * @code STOCK
     * @permission_desc Imprimer les écarts de passations de stocks en PDF
     */
    public function print_passations_by_agents_uuid(Request $request)
    {
        $auth = auth()->user();

        try {
            $agentFromId = $request->input('agent_from_id');
            if (!$agentFromId) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Agent initiateur non sélectionné.'
                ], 422);
            }

            $start_date = $request->filled('start_date')
                ? \Illuminate\Support\Carbon::createFromFormat('d-m-Y', $request->input('start_date'))->startOfDay()
                : now()->startOfDay();

            $end_date = $request->filled('end_date')
                ? \Illuminate\Support\Carbon::createFromFormat('d-m-Y', $request->input('end_date'))->endOfDay()
                : now()->endOfDay();

            $items = PassationItem::with('product')
                ->where('status', 'in_discuss')
                ->whereHas('passation', function($q) use ($agentFromId, $start_date, $end_date) {
                    $q->where('agent_from_id', $agentFromId)
                        ->when($start_date && $end_date, fn($q) => $q->whereBetween('created_at', [$start_date, $end_date]));
                })
                ->get();

            if ($items->isEmpty()) {
                return response()->json([
                    'message' => 'Aucune passation en discussion trouvée pour cet agent.'
                ], 404);
            }

            $cumulativeItems = $items->groupBy('product_uuid')->map(function($group) {
                return [
                    'product' => $group->first()->product,
                    'quantity_sent' => $group->sum('quantity_sent'),
                    'quantity_counted' => $group->sum('quantity_counted'),
                    'difference' => $group->sum('difference'),
                    'status' => 'in_discuss',
                ];
            });


            $manager = User::find($agentFromId);


            $fileName = 'ECARTS-PASSATIONS-' . strtoupper($manager->nom_utilisateur) . '-' . now()->format('YmdHis') . '.pdf';
            $folderPath = 'storage/passations-managers/' . $agentFromId;
            $filePath   = $folderPath . '/' . $fileName;

            if (!is_dir($folderPath)) {
                mkdir($folderPath, 0755, true);
            }

            $data = [
                'manager' => $manager,
                'cumulativeItems' => $cumulativeItems,
                'start_date' => $start_date->format('d/m/Y'),
                'end_date' => $end_date->format('d/m/Y'),
            ];

            save_browser_shot_pdf(
                view: 'pdfs.passations.passations_by_managers',
                data: $data,
                folderPath: $folderPath,
                path: $filePath,
                margins: [10, 10, 10, 10],
                footer: 'pdfs.reports.factures.footer'
            );

            $pdfContent = file_get_contents($filePath);
            $base64 = base64_encode($pdfContent);

            return response()->json([
                'data' => $data,
                'base64' => $base64,
                'url' => $filePath,
                'filename' => $fileName,
            ], 200);

        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la génération du PDF',
                'details' => $e->getMessage(),
            ], 500);
        }
    }



    /**
     * Display a listing of the resource.
     * @permission PassationController::export_passations_stocks
     * @code STOCK
     * @permission_desc Exporter les passations de stocks en Excel
     */
    public function export_passations_stocks(Request $request)
    {
        $filter = PassationsFilterData::fromRequestPassationsFilterData($request);
        $filename = 'LISTE-DES-PASSATIONS-DE-STOCKS-' . now()->format('dmY') . '.xlsx';

        $passationsStocksQuery = filter_passations($filter, false);

        Excel::store(new PassationsStocksExport($passationsStocksQuery), $filename, 'passations_stocks');

        return response()->json([
            "message" => "Exportation des données effectuée avec succès",
            "filename" => $filename,
            "url" => Storage::disk('passations_stocks')->url($filename)
        ]);
    }


    /**
     * Display a listing of the resource.
     * @permission PassationController::print_passations_by_filter
     * @code STOCK
     * @permission_desc Imprimer  passations de stocks en PDF
     */
    public function print_passations_by_filter(Request $request)
    {
        try {
            $filter = PassationsFilterData::fromRequestPassationsFilterData($request);

            $startDate = $filter->start_date
                ? Carbon::parse($filter->start_date)->startOfDay()
                : null;
            $endDate = $filter->end_date
                ? Carbon::parse($filter->end_date)->endOfDay()
                : null;

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
                'items'
            ]);

            if (!empty($filter->agent_from_id)) {
                $query->where('agent_from_id', $filter->agent_from_id);
            }

            if (!empty($filter->status)) {
                $query->where('status', $filter->status);
            }

            if (!empty($filter->warehouse_uuid)) {
                $query->where('warehouse_uuid', $filter->warehouse_uuid);
            }

            if (!empty($filter->search)) {
                $search = $filter->search;
                $query->where(function($q) use ($search) {
                    $q->where('reference', 'like', "%$search%")
                        ->orWhereHas('agentFrom', fn($q2) => $q2->where('nom_utilisateur', 'like', "%$search%"))
                        ->orWhereHas('agentTo', fn($q2) => $q2->where('nom_utilisateur', 'like', "%$search%"));
                });
            }

            if ($startDate && $endDate) {
                $query->whereBetween('created_at', [$startDate, $endDate]);
            }
            $query->orderByDesc('created_at');


            $passations = $query->skip(($filter->page - 1) * $filter->limit)
                ->take($filter->limit)
                ->get();

            if ($passations->isEmpty()) {
                return response()->json([
                    'message' => 'Aucune passation trouvée avec les filtres sélectionnés.'
                ], 404);
            }
            $manager = $filter->agent_from_id ? User::find($filter->agent_from_id) : null;
            $warehouse = $filter->warehouse_uuid ? Warehouse::find($filter->warehouse_uuid) : null;

            $fileName = 'PASSATIONS-' . now()->format('YmdHis') . '.pdf';
            $folderPath = 'storage/passations_by_filter';
            $filePath = $folderPath . '/' . $fileName;
            if (!is_dir($folderPath)) mkdir($folderPath, 0755, true);

            $data = [
                'manager' => $manager,
                'filter'     => $filter,
                'passations' => $passations,
                'start_date' => $startDate?->format('d/m/Y'),
                'end_date'   => $endDate?->format('d/m/Y'),
                'warehouse'  => $warehouse,
            ];

            save_browser_shot_pdf(
                view: 'pdfs.passations.passations_by_filter',
                data: $data,
                folderPath: $folderPath,
                path: $filePath,
                margins: [10, 10, 10, 10],
                footer: 'pdfs.reports.factures.footer'
            );

            $pdfContent = file_get_contents($filePath);
            $base64     = base64_encode($pdfContent);

            return response()->json([
                'data'     => $data,
                'base64'   => $base64,
                'url'      => $filePath,
                'filename' => $fileName,
            ], 200);

        } catch (\Throwable $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Erreur lors de la génération du PDF',
                'details' => $e->getMessage(),
            ], 500);
        }
    }










    //
}
