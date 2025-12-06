<?php

namespace App\Http\Controllers;

use App\Models\Passation;
use App\Models\PdfDocument;
use App\Models\Product;
use App\Models\ProductPoint;
use App\Models\PurchaseOrder;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
/**
 * @permission_category Gestion des régularisations de stocks
 */
class StockAdjustmentController extends Controller
{
    /**
     * Display a listing of the resource.
     * @permission StockAdjustmentController::store
     * @permission_desc Enregistrer une régularisation de stocks
     */
    public function store(Request $request)
    {
        $auth = auth()->user();

        // 🔹 Validation des données d’entrée
        $data = $request->validate([
            'warehouse_uuid'        => 'nullable|exists:warehouses,uuid',
            'notes'                 => 'required|string',
            'comment'               => 'nullable|string',
            'action'                => 'nullable|integer',

            'items'                 => 'required|array|min:1',
            'items.*.product_uuid'  => 'required|exists:produits,uuid',
            'items.*.quantity'      => 'required|integer|min:1',
        ], [
            'notes.required' => 'La note est obligatoire.',
            'items.required' => 'Veuillez ajouter au moins un article.',
            'items.*.product_uuid.required' => "Le produit est obligatoire.",
            'items.*.quantity.required' => "La quantité est obligatoire.",
        ]);

        DB::beginTransaction();
        $warehouseUuid = $data['warehouse_uuid'];

        try {

            // 🔹 Vérification des stocks
            foreach ($data['items'] as $index => $item) {

                $product = Product::where('uuid', $item['product_uuid'])->first();

                if (!$product) {
                    return response()->json([
                        'status' => 'error',
                        'message' => "Produit introuvable.",
                    ], 404);
                }
                $productPoint = ProductPoint::where('produit_uuid', $item['product_uuid'])
                    ->where('point_uuid', $warehouseUuid)
                    ->first();

                $stockDisponible = $productPoint->quantity ?? 0;


                if ($item['quantity'] > $stockDisponible) {
                    $qtyStocks = rtrim(rtrim($stockDisponible, '0'), '.');
                    return response()->json([
                        'errors' => [
                            'items' => [
                                $index => [
                                    'quantity' => [
                                        "La quantité ({$item['quantity']}) ne peut pas dépasser le stock disponible."
                                    ]
                                ]
                            ]
                        ]
                    ], 422);
                }
            }

            // 🔹 Création de l’ajustement
            $adjustment = StockAdjustment::create([
                'warehouse_uuid' => $data['warehouse_uuid'] ?? null,
                'notes'          => $data['notes'],
                'comment'        => $data['comment'] ?? null,
                'action'         => $data['action'] ?? 1,
                'created_by'     => $auth->id,
                'updated_by'     => $auth->id,
                'status'         => 'pending',
            ]);

            // 🔹 Ajout des items
            foreach ($data['items'] as $item) {
                StockAdjustmentItem::create([
                    'stock_adjustment_uuid' => $adjustment->uuid,
                    'product_uuid'          => $item['product_uuid'],
                    'quantity'              => $item['quantity'],
                    'created_by'            => $auth->id,
                    'updated_by'            => $auth->id,
                ]);
            }

            DB::commit();

            return response()->json([
                'status'  => 'success',
                'message' => 'Ajustement de stock créé avec succès.',
                'data'    => $adjustment->load('items'),
            ], 201);

        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur est survenue lors de la création de l’ajustement.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display a listing of the resource.
     * @permission StockAdjustmentController::update
     * @permission_desc Modifier une régularisation de stocks
     */
    public function update(Request $request, string $uuid)
    {
        $auth = auth()->user();

        // 🔹 Récupération de l’ajustement
        $adjustment = StockAdjustment::where('uuid', $uuid)->first();
        if (!$adjustment) {
            return response()->json([
                'status' => 'error',
                'message' => 'Ajustement introuvable.',
            ], 404);
        }

        // 🔹 Validation des données
        $data = $request->validate([
            'warehouse_uuid'        => 'nullable|exists:warehouses,uuid',
            'notes'                 => 'required|string',
            'comment'               => 'nullable|string',
            'action'                => 'nullable|integer',

            'items'                 => 'required|array|min:1',
            'items.*.product_uuid'  => 'required|exists:produits,uuid',
            'items.*.quantity'      => 'required|integer|min:1',
        ], [
            'notes.required' => 'La note est obligatoire.',
            'items.required' => 'Veuillez ajouter au moins un article.',
            'items.*.product_uuid.required' => "Le produit est obligatoire.",
            'items.*.quantity.required' => "La quantité est obligatoire.",
        ]);

        DB::beginTransaction();
        $warehouseUuid = $data['warehouse_uuid'];

        try {

            // 🔹 Vérification des stocks
            foreach ($data['items'] as $index => $item) {

                $product = Product::where('uuid', $item['product_uuid'])->first();
                if (!$product) {
                    return response()->json([
                        'status' => 'error',
                        'message' => "Produit introuvable.",
                    ], 404);
                }

                $productPoint = ProductPoint::where('produit_uuid', $item['product_uuid'])
                    ->where('point_uuid', $warehouseUuid)
                    ->first();

                $stockDisponible = $productPoint->quantity ?? 0;


                if ($item['quantity'] > $stockDisponible) {
                    $qtyStocks = rtrim(rtrim($stockDisponible, '0'), '.');
                    return response()->json([
                        'errors' => [
                            'items' => [
                                $index => [
                                    'quantity' => [
                                        "La quantité ({$item['quantity']}) ne peut pas dépasser le stock disponible."
                                    ]
                                ]
                            ]
                        ]
                    ], 422);
                }
            }

            // 🔹 Mise à jour de l’ajustement
            $adjustment->update([
                'warehouse_uuid' => $data['warehouse_uuid'] ?? $adjustment->warehouse_uuid,
                'notes'          => $data['notes'],
                'comment'        => $data['comment'] ?? $adjustment->comment,
                'action'         => $data['action'] ?? $adjustment->action,
                'updated_by'     => $auth->id,
            ]);

            // 🔹 Suppression des anciens items
            StockAdjustmentItem::where('stock_adjustment_uuid', $adjustment->uuid)->delete();

            // 🔹 Ajout des nouveaux items
            foreach ($data['items'] as $item) {
                StockAdjustmentItem::create([
                    'stock_adjustment_uuid' => $adjustment->uuid,
                    'product_uuid'          => $item['product_uuid'],
                    'quantity'              => $item['quantity'],
                    'created_by'            => $auth->id,
                    'updated_by'            => $auth->id,
                ]);
            }

            DB::commit();

            return response()->json([
                'status'  => 'success',
                'message' => 'Ajustement de stock mis à jour avec succès.',
                'data'    => $adjustment->load('items'),
            ], 200);

        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur est survenue lors de la mise à jour de l’ajustement.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }


    /**
     * Display a listing of the resource.
     * @permission StockAdjustmentController::index
     * @permission_desc Afficher la liste des régularisations de stocks
     */
    public function index(Request $request)
    {
        $auth = auth()->user();
        $perPage = $request->input('limit', 25);
        $page = $request->input('page', 1);

        $stock_adjustment = StockAdjustment::with([
            'warehouse',
            'items.product',
            'creator',
            'updater',
            'validator',
        ]);
        if ($request->filled('status')) {
            $stock_adjustment->where('status', $request->status);
        }
        if ($request->filled('action')) {
            $stock_adjustment->where('action', $request->action);
        }

        if ($search = trim($request->input('search'))) {
            $stock_adjustment->where(function ($q) use ($search) {
                $q->where('uuid', 'like', "%{$search}%")
                    ->orWhere('status', 'like', "%{$search}%")
                    ->orWhere('notes', 'like', "%{$search}%")
                    ->orWhere('comment', 'like', "%{$search}%")
                    ->orWhere('action', 'like', "%{$search}%")
                    ->orWhere('reference', 'like', "%{$search}%")


                    // 🔹 Entrepôts
                    ->orWhereHas('warehouse', function ($qw) use ($search) {
                        $qw->where('name', 'like', "%{$search}%")
                            ->orWhere('uuid', 'like', "%{$search}%")
                            ->orWhere('ref', 'like', "%{$search}%")
                            ->orWhere('address', 'like', "%{$search}%")
                            ->orWhere('stock_type', 'like', "%{$search}%");
                    })

                    ->orWhereHas('creator', function ($qc) use ($search) {
                        $qc->where('nom_utilisateur', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%")
                            ->orWhere('login', 'like', "%{$search}%")
                            ->orWhere('id', 'like', "%{$search}%");
                    })
                    ->orWhereHas('updater', function ($qu) use ($search) {
                        $qu->where('nom_utilisateur', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%")
                            ->orWhere('login', 'like', "%{$search}%")
                            ->orWhere('id', 'like', "%{$search}%");
                    })
                    ->orWhereHas('validator', function ($qv) use ($search) {
                        $qv->where('nom_utilisateur', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%")
                            ->orWhere('login', 'like', "%{$search}%")
                            ->orWhere('id', 'like', "%{$search}%");
                    })
                    // 🔹 Produits
                    ->orWhereHas('items.product', function ($qp) use ($search) {
                        $qp->where('name', 'like', "%{$search}%")
                            ->orWhere('code', 'like', "%{$search}%")
                            ->orWhere('description', 'like', "%{$search}%")
                            ->orWhere('uuid', 'like', "%{$search}%");
                    });
            });
        }

        // 🔹 Pagination
        $data = $stock_adjustment->latest()->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data'         => $data->items(),
            'current_page' => $data->currentPage(),
            'last_page'    => $data->lastPage(),
            'total'        => $data->total(),
        ]);


    }

    /**
     * Display a listing of the resource.
     * @permission StockAdjustmentController::show
     * @permission_desc Afficher les détails d'une régularisation de stocks
     */
    public function show($uuid)
    {
        // Récupérer la passation avec ses relations
        $stock_adjustment = StockAdjustment::with([
            'warehouse',
            'items.product',
            'creator',
            'updater',
            'validator',
        ])
            ->where('uuid', $uuid)
            ->firstOrFail();

        if (!$stock_adjustment) {
            return response()->json([
                'status' => 'error',
                'message' => 'Ajustement de stock introuvable.',
                ''
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'stock_adjustment' => $stock_adjustment,
        ]);
    }

    /**
     * Display a listing of the resource.
     * @permission StockAdjustmentController::cancel_stock_adjustment
     * @permission_desc Annuler une régularisation de stocks
     */
    public function cancel_stock_adjustment(Request $request, string $uuid){
        $auth = auth()->user();
        $request->validate([
            'password' => 'required|string'
        ]);

        // Vérification du mot de passe
        if (!Hash::check($request->password, $auth->password)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Mot de passe incorrect.'
            ], 422);
        }

        $stock_adjustment = StockAdjustment::findOrFail($uuid);
        $stock_adjustment->update([
            'status' => 'cancelled',
            'updated_by' => auth()->id(),
            'cancelled_by' => auth()->id(),
            'cancelled_at' => now(),
        ]);

        return response()->json([
            'message' => "Ajustement de stock annulé avec succès.",
            'order' => $stock_adjustment
        ]);
    }

    /**
     * Display a listing of the resource.
     * @permission StockAdjustmentController::validated_stock_adjustment
     * @permission_desc Valider une régularisation de stocks
     */
    public function validated_stock_adjustment(Request $request, string $uuid)
    {
        $auth = auth()->user();

        // 🔹 Vérification du mot de passe
        $request->validate([
            'password' => 'required|string'
        ]);

        if (!Hash::check($request->password, $auth->password)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Mot de passe incorrect.'
            ], 401);
        }

        DB::beginTransaction();

        try {

            $stock_adjustment = StockAdjustment::with('items.product')->findOrFail($uuid);

            if ($stock_adjustment->status === 'validated') {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Cet ajustement a déjà été validé.'
                ], 400);
            }

            // 🔹 Entrepôt concerné par l’ajustement
            $warehouseUuid = $stock_adjustment->warehouse_uuid;

            foreach ($stock_adjustment->items as $item) {

                $product = $item->product;
                if (!$product) {
                    DB::rollBack();
                    return response()->json([
                        'status'  => 'error',
                        'message' => "Produit introuvable pour l'article {$item->uuid}."
                    ], 404);
                }

                // 🔹 Récupérer le stock dans l’entrepôt
                $productPoint = ProductPoint::where('produit_uuid', $product->uuid)
                    ->where('point_uuid', $warehouseUuid)
                    ->first();

                $stockDispo = $productPoint->quantity ?? 0;

                // --------------------------------------------
                // ACTIONS (1 = ajout, 2/3/4 = déduction)
                // --------------------------------------------

                if (in_array($stock_adjustment->action, [2, 3, 4])) {

                    // 🔹 Vérifier stock suffisant dans l’entrepôt
                    if ($item->quantity > $stockDispo) {
                        DB::rollBack();
                        return response()->json([
                            'status'  => 'error',
                            'message' => "Stock insuffisant pour {$product->name} dans cet entrepôt. Disponible : {$stockDispo}, requis : {$item->quantity}."
                        ], 422);
                    }

                    // 🔹 Déduire du stock de l'entrepôt
                    $productPoint->decrement('quantity', $item->quantity);

                } elseif ($stock_adjustment->action == 1) {

                    // 🔹 Ajouter au stock de l'entrepôt
                    if ($productPoint) {
                        $productPoint->increment('quantity', $item->quantity);
                    } else {
                        // Si pas encore d'entrée pour cet entrepôt → création
                        ProductPoint::create([
                            'produit_uuid' => $product->uuid,
                            'point_uuid'   => $warehouseUuid,
                            'quantity'     => $item->quantity
                        ]);
                    }
                }
            }

            // 🔹 Valider l’ajustement
            $stock_adjustment->update([
                'status'        => 'validated',
                'validated_by'  => $auth->id,
                'validated_at'  => now(),
                'updated_by'    => $auth->id
            ]);

            DB::commit();

            return response()->json([
                'status'  => 'success',
                'message' => 'Ajustement de stock validé avec succès.',
                'data'    => $stock_adjustment->load('items.product')
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur est survenue lors de la validation.',
                'details' => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Display a listing of the resource.
     * @permission StockAdjustmentController::print_stock_adjustment
     * @permission_desc Imprimer une régularisation de stocks en PDF
     */
    public function print_stock_adjustment(Request $request, string $uuid)
    {
        $auth = auth()->user();
        try {
            DB::beginTransaction();
            $stock_adjustment = StockAdjustment::with([
                'warehouse',
                'items.product',
                'creator',
                'updater',
                'validator',
            ])
                ->where('uuid', $uuid)
                ->firstOrFail();

            $fileName   = strtoupper('DETAILS-REGULARISATIONS-' . now()->format('YmdHis') . '.pdf');
            $folderPath = 'storage/details-regularisations/' .$stock_adjustment->uuid;
            $filePath   = $folderPath . '/' . $fileName;

            if (!is_dir($folderPath)) {
                if (!mkdir($folderPath, 0755, true) && !is_dir($folderPath)) {
                    throw new \RuntimeException("Impossible de créer le répertoire : {$folderPath}");
                }
            }
            $data = ['stock_adjustment' => $stock_adjustment];

            $footer = 'pdfs.reports.factures.footer';

            save_browser_shot_pdf(
                view: 'pdfs.details-regularisations.details-regularisations',
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

            // Chercher si le document existe déjà
            $pdf = PdfDocument::where('order_uuid', $stock_adjustment->uuid)
                ->where('name', 'DETAILS-REGULARISATIONS')
                ->first();

            // S'il existe → on met à jour le fichier
            if ($pdf) {
                $pdf->update([
                    'path'       => $filePath,
                    'filename'   => $fileName,
                    'updated_by' => $auth->id,
                ]);
            }
            // Sinon → on crée un nouvel enregistrement
            else {
                $pdf = PdfDocument::create([
                    'name'       => 'DETAILS-REGULARISATIONS',
                    'order_uuid' => $stock_adjustment->uuid,
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



    //
}
