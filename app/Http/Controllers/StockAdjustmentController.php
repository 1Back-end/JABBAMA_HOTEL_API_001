<?php

namespace App\Http\Controllers;

use App\DTO\PurchaseOrderFilterData;
use App\DTO\StockAdjustmentFilterData;
use App\Enums\StockAdjustmentAction;
use App\Exports\PurchaseOrdersExport;
use App\Exports\StockAdjustmentsExport;
use App\Models\Passation;
use App\Models\PdfDocument;
use App\Models\Product;
use App\Models\ProductPoint;
use App\Models\PurchaseOrder;
use App\Models\StockAdjustment;
use App\Models\StockAdjustmentItem;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;

/**
 * @permission_category Gestion des régularisations de stocks
 */
class StockAdjustmentController extends Controller
{
    public function typeStockAdjustment()
    {
        return response()->json([
            'status' => 'success',
            'data'   => StockAdjustmentAction::TO_ARRAY(),
        ]);
    }

    /**
     * Display a listing of the resource.
     * @permission StockAdjustmentController::store
     * @permission_desc Enregistrer une régularisation de stocks
     */
    public function store(Request $request)
    {
        $auth = auth()->user();

        $data = $request->validate([
            'warehouse_uuid'        => 'required|exists:warehouses,uuid',
            'notes'                 => 'nullable|string',
            'comment'               => 'nullable|string',
            'action'                => [
                'required',
                'integer',
                Rule::in(array_column(StockAdjustmentAction::cases(), 'value')),
            ],
            'items'                 => 'required|array|min:1',
            'items.*.product_uuid'  => 'required|exists:produits,uuid',
            'items.*.quantity'      => 'required|integer|min:1',
        ]);

        DB::beginTransaction();

        try {
            $adjustment = StockAdjustment::create([
                'warehouse_uuid' => $data['warehouse_uuid'],
                'notes'          => $data['notes'],
                'comment'        => $data['comment'] ?? null,
                'action'         => $data['action'],
                'status'         => 'pending', // ⏳
                'created_by'     => $auth->id,
                'updated_by'     => $auth->id,
            ]);

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
                'message' => 'Ajustement créé (en attente de validation).',
                'data'    => $adjustment->load('items'),
            ], 201);

        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la création.',
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

        $adjustment = StockAdjustment::where('uuid', $uuid)->firstOrFail();

        if ($adjustment->status !== 'pending') {
            return response()->json([
                'message' => 'Impossible de modifier un ajustement déjà validé.'
            ], 403);
        }

        $data = $request->validate([
            'warehouse_uuid'        => 'required|exists:warehouses,uuid',
            'action'                => [
                'required',
                'integer',
                Rule::in(array_column(StockAdjustmentAction::cases(), 'value')),
            ],
            'notes'                => 'nullable|string',
            'items'                => 'required|array|min:1',
            'items.*.product_uuid' => 'required|exists:produits,uuid',
            'items.*.quantity'     => 'required|integer|min:1',
        ]);

        DB::beginTransaction();

        try {
            $adjustment->update([
                'warehouse_uuid' => $data['warehouse_uuid'],
                'action'          => $data['action'],
                'notes'      => $data['notes'],
                'updated_by' => $auth->id,
                'status'     => 'pending',
            ]);

            // 🔄 Reset items
            $adjustment->items()->delete();

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
                'message' => 'Ajustement modifié avec succès.',
                'data'    => $adjustment->load('items'),
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Erreur lors de la modification.',
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
        if ($request->has('action') && $request->action !== null && $request->action !== '') {
            $stock_adjustment->where('action', $request->action);
        }
        if ($request->filled('warehouse_uuid')) {
            $stock_adjustment->where('warehouse_uuid', $request->warehouse_uuid);
        }
        if ($request->filled('start_date') && $request->filled('end_date')) {
            $start = \Illuminate\Support\Carbon::parse($request->start_date)->startOfDay();
            $end = Carbon::parse($request->end_date)->endOfDay();

            $stock_adjustment->whereBetween('created_at', [$start, $end]);
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
     * @permission StockAdjustmentController::destroy
     * @permission_desc Supprimer une régularisation de stocks
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

        $adjustment = StockAdjustment::with('items')
            ->where('uuid', $uuid)
            ->firstOrFail();

        DB::beginTransaction();

        try {

            // 👉 Si l’ajustement était validé, on restaure le stock
            if ($adjustment->status === 'validated') {

                foreach ($adjustment->items as $item) {

                    $productPoint = ProductPoint::where(
                        'produit_uuid', $item->product_uuid
                    )->where(
                        'point_uuid', $adjustment->warehouse_uuid
                    )->first();

                    if (!$productPoint) {
                        continue;
                    }

                    switch (StockAdjustmentAction::from($adjustment->action)) {

                        case StockAdjustmentAction::AJUSTEMENT_PLUS:
                            // On annule l’ajout
                            $productPoint->quantity -= $item->quantity;
                            break;

                        case StockAdjustmentAction::AVARIE:
                        case StockAdjustmentAction::DEDUCTION:
                        case StockAdjustmentAction::AJUSTEMENT_MOINS:
                            // On annule la déduction
                            $productPoint->quantity += $item->quantity;
                            break;
                    }

                    $productPoint->save();
                }
            }

            // Suppression de l’ajustement
            $adjustment->delete();

            DB::commit();

            return response()->json([
                'status'  => 'success',
                'message' => 'Ajustement supprimé et stock restauré avec succès.'
            ]);

        } catch (\Throwable $e) {

            DB::rollBack();

            return response()->json([
                'status'  => 'error',
                'message' => 'Erreur lors de la suppression.',
                'details' => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Display a listing of the resource.
     * @permission StockAdjustmentController::validated_stock_adjustment
     * @permission_desc Valider une régularisation de stocks
     */
    public function validated_stock_adjustment(Request $request, string $uuid)
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

        $adjustment = StockAdjustment::with('items')
            ->where('uuid', $uuid)
            ->firstOrFail();

        if ($adjustment->status !== 'pending') {
            return response()->json([
                'status'  => 'error',
                'message' => 'Cet ajustement a déjà été validé ou annulé.'
            ], 403);
        }

        DB::beginTransaction();

        try {
            foreach ($adjustment->items as $item) {

                $productPoint = ProductPoint::firstOrCreate(
                    [
                        'produit_uuid' => $item->product_uuid,
                        'point_uuid'   => $adjustment->warehouse_uuid,
                    ],
                    ['quantity' => 0]
                );
                $productName = $item->product->name ?? $item->product_uuid;

                switch (StockAdjustmentAction::from($adjustment->action)) {

                    case StockAdjustmentAction::AVARIE:
                    case StockAdjustmentAction::DEDUCTION:
                    case StockAdjustmentAction::AJUSTEMENT_MOINS:
                        if ($item->quantity > $productPoint->quantity) {
                            throw new \Exception(
                                "Stock insuffisant pour le produit {$productName}"
                            );
                        }

                        $productPoint->quantity -= $item->quantity;
                        $productPoint->save();
                        break;

                    case StockAdjustmentAction::AJUSTEMENT_PLUS:
                        $productPoint->quantity += $item->quantity;
                        $productPoint->save();
                        break;

                }
            }

            $adjustment->update([
                'status'       => 'validated',
                'validated_by' => $auth->id,
                'validated_at' => now(),
            ]);

            DB::commit();

            return response()->json([
                'status'  => 'success',
                'message' => 'Ajustement validé avec succès.',
                'data'    => $adjustment->load('items'),
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status'  => 'error',
                'message' => 'Erreur lors de la validation de l’ajustement.',
                'details' => $e->getMessage(),
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

    /**
     * Display a listing of the resource.
     * @permission StockAdjustmentController::export_stock_adjustment
     * @permission_desc Exporter la régularisation des stocks en Excel(En fonction du filtre)
     */
    public function export_stock_adjustment(Request $request)
    {
        $filter = StockAdjustmentFilterData::fromRequestStockAdjustmentFilterData($request);
        $filename = 'LISTE-DES-REGULARISATIONS DE STOCKS-' . now()->format('dmY') . '.xlsx';

        $stocksAdjustmentQuery = filter_stocks_adjustment($filter, false);

        Excel::store(new StockAdjustmentsExport($stocksAdjustmentQuery), $filename, 'stock_adjustment');

        return response()->json([
            "message" => "Exportation des données effectuée avec succès",
            "filename" => $filename,
            "url" => Storage::disk('stock_adjustment')->url($filename)
        ]);
    }


    /**
     * Display a listing of the resource.
     * @permission StockAdjustmentController::print_stock_adjustments_by_action
     * @permission_desc Imprimer les rapports de régularisations de stocks
     */
    public function print_stock_adjustments_by_action(Request $request)
    {
        try {
            $action = (int) $request->input('action');
            Log::info("Action reçue : {$action}");

            // Vérification action via l'enum
            if (!array_key_exists($action, StockAdjustmentAction::TO_ARRAY())) {
                Log::warning("Action invalide : {$action}");
                return response()->json([
                    'status' => 'error',
                    'message' => 'Action invalide.'
                ], 422);
            }

            // Dates optionnelles
            $start_date = $request->input('start_date') ? Carbon::parse($request->input('start_date'))->startOfDay() : null;
            $end_date   = $request->input('end_date') ? Carbon::parse($request->input('end_date'))->endOfDay() : null;

            Log::info("Filtre de date : start_date={$start_date}, end_date={$end_date}");

            // Récupération des régularisations
            $stock_adjustments = StockAdjustment::with([
                'warehouse',
                'items.product',
                'creator',
                'updater',
                'validator',
            ])
                ->where('action', $action)
                ->when($start_date && $end_date, function ($q) use ($start_date, $end_date) {
                    $q->whereBetween('created_at', [$start_date, $end_date]);
                })
                ->get();

            Log::info("Nombre de régularisations trouvées : " . $stock_adjustments->count());

            if ($stock_adjustments->isEmpty()) {
                Log::warning("Aucune régularisation trouvée pour action={$action} entre {$start_date} et {$end_date}");
                return response()->json([
                    'status' => 'error',
                    'message' => 'Aucune régularisation trouvée pour cette action et cette période.'
                ], 404);
            }

            // Nom du fichier
            $actionLabel = StockAdjustmentAction::LABEL($action);
            $fileName = 'RAPPORTS-REGULARISATION-STOCKS-' . $actionLabel . '-' . now()->format('YmdHis') . '.pdf';
            $folderPath = 'storage/rapports_stock_adjustment/' . strtolower($actionLabel);
            $filePath = $folderPath . '/' . $fileName;

            if (!is_dir(public_path($folderPath))) {
                mkdir(public_path($folderPath), 0755, true);
                Log::info("Dossier créé : {$folderPath}");
            }

            $data = [
                'stock_adjustments' => $stock_adjustments,
                'action_label'      => $actionLabel,
                'start_date'        => $start_date ? $start_date->format('d/m/Y') : null,
                'end_date'          => $end_date ? $end_date->format('d/m/Y') : null,
            ];

            $footer = 'pdfs.reports.factures.footer';

            save_browser_shot_pdf(
                view: 'pdfs.passations.rapports_stock_adjustment',
                data: $data,
                folderPath: $folderPath,
                path: $filePath,
                margins: [10, 10, 10, 10],
                footer: $footer
            );

            Log::info("PDF généré : {$filePath}");

            $pdfContent = file_get_contents($filePath);
            $base64     = base64_encode($pdfContent);

            return response()->json([
                'base64'   => $base64,
                'url'      => $filePath,
                'filename' => $fileName,
            ], 200);

        } catch (\Throwable $e) {
            Log::error("Erreur génération PDF : " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            return response()->json([
                'status'  => 'error',
                'message' => 'Erreur lors de la génération du PDF',
                'details' => $e->getMessage(),
            ], 500);
        }
    }




    //
}
