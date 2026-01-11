<?php

namespace App\Http\Controllers;

use App\DTO\DeductionsStocksFiliterData;
use App\Enums\StocksDeductionsStatus;
use App\Exports\StocksDeductionsExport;
use App\Models\PdfDocument;
use App\Models\ProductPoint;
use App\Models\StockDeduction;
use App\Models\StockDeductionItem;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

/**
 * @permission_category Gestion des déductions de stocks
 */
class StockDeductionController extends Controller
{

    /**
     * Display a listing of the resource.
     * @permission StockDeductionController::print_stocks_deductions
     * @permission_desc Imprimer les détails de déductions de stocks en PDF
     */
    public function print_stocks_deductions(Request $request, string $uuid)
    {
        try {
            DB::beginTransaction();
            $auth = auth()->user();
            $deduction = StockDeduction::with([
                'items.product',
                'warehouse',
                'creator',
                'updater',
                'validator',
                'canceler'
            ])->findOrFail($uuid);

        $fileName   = strtoupper('DEDUCTION-DE-STOCKS-N°-' . strtoupper($deduction->reference) . '-'. '.pdf');
        $folderPath = 'storage/details-stocks-deductions/' . $deduction->uuid;
        $filePath   = $folderPath . '/' . $fileName;

        // Créer le dossier si nécessaire
        if (!is_dir($folderPath)) {
            if (!mkdir($folderPath, 0755, true) && !is_dir($folderPath)) {
                throw new \RuntimeException("Impossible de créer le répertoire : {$folderPath}");
            }
        }

        $data = ['deduction' => $deduction];

        $footer = 'pdfs.reports.factures.footer';

        save_browser_shot_pdf(
            view: 'pdfs.details-stocks-deductions.details-stocks-deductions',
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
        $pdf = PdfDocument::where('order_uuid', $deduction->uuid)
            ->where('name', 'STOCKS-DEDUCTIONS')
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
                'name'       => 'STOCKS-DEDUCTIONS',
                'order_uuid' => $deduction->uuid,
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
     * @permission StockDeductionController::export_stocks_deductions
     * @permission_desc Exporter les déductions de stocks en Excel
     */
    public function export_stocks_deductions(Request $request)
    {
        $filter = DeductionsStocksFiliterData::fromRequestDeductionsStocksFiliterData($request);
        $filename = 'LISTE-DES-DEDUCTIONS-DE-STOCKS-' . now()->format('dmY') . '.xlsx';

        $stocksDedudctions = filter_stocks_deductions($filter, false);

        Excel::store(new StocksDeductionsExport($stocksDedudctions), $filename, 'stocks_deductions');
        return response()->json([
            "message" => "Exportation des données effectuée avec succès",
            "filename" => $filename,
            "url" => Storage::disk('stocks_deductions')->url($filename)
        ]);

    }

    public function TypeStocksDeductionsStatus()
    {
        return response()->json([
            'status' => 'success',
            'data'   => StocksDeductionsStatus::toArray(),
        ]);
    }

    /**
     * Display a listing of the resource.
     * @permission StockDeductionController::index
     * @permission_desc Afficher la liste des déductions de stocks
     */
    public function index(Request $request)
    {
        $auth = auth()->user();
        $perPage = $request->input('limit', 25);
        $page = $request->input('page', 1);

        $deduction = StockDeduction::with([
            'items.product',
            'warehouse',
            'creator',
            'updater',
            'validator',
            'canceler'
        ]);
        if ($request->filled('status')) {
            $deduction->where('status', $request->status);
        }

        if ($request->filled('warehouse_uuid')) {
            $deduction->where('warehouse_uuid', $request->warehouse_uuid);
        }
        if ($request->filled('start_date') && $request->filled('end_date')) {
            $start = \Illuminate\Support\Carbon::parse($request->start_date)->startOfDay();
            $end = Carbon::parse($request->end_date)->endOfDay();

            $deduction->whereBetween('created_at', [$start, $end]);
        }
        if (!$auth->hasRole('SUPER_ADMIN')) {
            // Si l'utilisateur n'est pas SUPER_ADMIN, il voit seulement ses déductions
            $deduction->where('created_by', $auth->id);
        }

        if ($search = trim($request->input('search'))) {
            $deduction->where(function ($q) use ($search) {
                $q->where('uuid', 'like', "%{$search}%")
                    ->orWhere('status', 'like', "%{$search}%")
                    ->orWhere('comment', 'like', "%{$search}%")
                    ->orWhere('reason_of_cancel', 'like', "%{$search}%")
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
        $data = $deduction->latest()->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data'         => $data->items(),
            'current_page' => $data->currentPage(),
            'last_page'    => $data->lastPage(),
            'total'        => $data->total(),
        ]);
    }


    /**
     * Display a listing of the resource.
     * @permission StockDeductionController::store
     * @permission_desc Créer une déduction de stocks
     */
    public function store(Request $request)
    {
        $auth = auth()->user();

        $request->validate([
            'warehouse_uuid' => 'nullable|exists:warehouses,uuid',
            'comment' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_uuid' => 'required|exists:produits,uuid',
            'items.*.quantity' => 'required|integer|min:1',
        ], [
            'items.required' => 'Veuillez ajouter au moins un produit à la déduction.',
            'items.*.product_uuid.required' => 'Le produit est obligatoire.',
            'items.*.product_uuid.exists' => 'Le produit sélectionné est invalide.',
            'items.*.quantity.required' => 'La quantité est obligatoire.',
            'items.*.quantity.min' => 'La quantité doit être supérieure à zéro.',
        ]);

        DB::transaction(function () use ($request, $auth) {

            $deduction = StockDeduction::create([
                'reference' => $request->reference,
                'warehouse_uuid' => $request->warehouse_uuid,
                'comment' => $request->comment,
                'created_by' => $auth->id,
                'status' => 'draft',
            ]);

            foreach ($request->items as $item) {
                StockDeductionItem::create([
                    'stocks_deduction_uuid' => $deduction->uuid,
                    'product_uuid' => $item['product_uuid'],
                    'quantity' => $item['quantity'],
                    'created_by' => $auth->id,
                ]);
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'La déduction de stock a été créée avec succès.'
        ], 201);
    }


    /**
     * Display a listing of the resource.
     * @permission StockDeductionController::update
     * @permission_desc Modifier une déduction de stocks
     */
    public function update(Request $request, string $uuid)
    {
        $auth = auth()->user();

        $deduction = StockDeduction::where('uuid', $uuid)->firstOrFail();

        if ($deduction->status !== 'draft') {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de modifier une déduction déjà validée ou annulée.'
            ], 422);
        }

        $request->validate([
            'warehouse_uuid' => 'nullable|exists:warehouses,uuid',
            'comment' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.product_uuid' => 'required|exists:produits,uuid',
            'items.*.quantity' => 'required|integer|min:1',
        ], [
            'items.required' => 'Veuillez ajouter au moins un produit.',
            'items.*.product_uuid.required' => 'Le produit est obligatoire.',
            'items.*.quantity.min' => 'La quantité doit être supérieure à zéro.',
        ]);

        DB::transaction(function () use ($request, $deduction, $auth) {

            // Mise à jour entête
            $deduction->update([
                'warehouse_uuid' => $request->warehouse_uuid,
                'comment' => $request->comment,
                'updated_by' => $auth->id,
                'status' => 'draft',
            ]);

            // Suppression logique des anciens items
            StockDeductionItem::where('stocks_deduction_uuid', $deduction->uuid)
                ->delete();

            // Réinsertion des nouveaux items
            foreach ($request->items as $item) {
                StockDeductionItem::create([
                    'stocks_deduction_uuid' => $deduction->uuid,
                    'product_uuid' => $item['product_uuid'],
                    'quantity' => $item['quantity'],
                    'updated_by' => $auth->id,
                ]);
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'La déduction de stock a été mise à jour avec succès.'
        ]);
    }

    /**
     * Display a listing of the resource.
     * @permission StockDeductionController::show
     * @permission_desc Afficher les détails d'une déduction de stocks
     */
    public function show(string $uuid)
    {
        $deduction = StockDeduction::with([
            'items.product',
            'warehouse',
            'creator',
            'updater',
            'validator',
            'canceler'
        ])->where('uuid', $uuid)->firstOrFail();

        if (!$deduction) {
            return response()->json([
                'success' => false,
                'message' => 'Déduction de stock non trouvée.'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'deduction' => $deduction,
            'message' => 'Déduction de stock récupérée avec succès.'
        ]);
    }


    /**
     * Display a listing of the resource.
     * @permission StockDeductionController::destroy
     * @permission_desc Supprimer une déduction de stocks
     */
    public function destroy(Request $request, string $uuid)
    {
        $auth = auth()->user();

        // Valider le mot de passe
        $request->validate([
            'password' => 'required|string',
        ], [
            'password.required' => 'Veuillez saisir votre mot de passe pour confirmer.',
        ]);

        if (!Hash::check($request->password, $auth->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Mot de passe incorrect. Suppression annulée.'
            ], 422);
        }

        $deduction = StockDeduction::where('uuid', $uuid)->first();

        if (!$deduction) {
            return response()->json([
                'success' => false,
                'message' => 'Déduction de stock non trouvée.'
            ], 404);
        }

        if ($deduction->status === 'validated') {
            foreach ($deduction->items as $item) {

                $productPoint = ProductPoint::where(
                    'produit_uuid', $item->product_uuid
                )->where(
                    'point_uuid', $deduction->warehouse_uuid
                )->first();

                $productPoint->quantity += $item->quantity;

                $productPoint->save();
            }
        }

        DB::transaction(function () use ($deduction) {
            $deduction->items()->delete();
            $deduction->delete();
        });

        return response()->json([
            'success' => true,
            'message' => 'La déduction de stock a été supprimée avec succès.'
        ]);
    }


    /**
     * Display a listing of the resource.
     * @permission StockDeductionController::cancel_deductions_stocks
     * @permission_desc Annuler une déduction de stocks
     */
    public function cancel_deductions_stocks(Request $request, string $uuid){
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

        $deduction = StockDeduction::findOrFail($uuid);
        $deduction->update([
            'status' => 'cancelled',
            'updated_by' => auth()->id(),
            'cancelled_by' => auth()->id(),
            'cancelled_at' => now(),
        ]);

        return response()->json([
            'message' => "La déduction de stock a été annulée avec succès.",
            'deduction' => $deduction
        ]);
    }


    /**
     * Display a listing of the resource.
     * @permission StockDeductionController::validated_deductions_stocks
     * @permission_desc Valider une déduction de stocks
     */
    public function validated_deductions_stocks(Request $request, string $uuid)
    {
        $auth = auth()->user();

        // 1️⃣ Vérifier le mot de passe
        $request->validate([
            'password' => 'required|string'
        ]);

        if (!Hash::check($request->password, $auth->password)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Mot de passe incorrect.'
            ], 422);
        }

        // 2️⃣ Récupérer la déduction avec ses items et produits
        $deduction = StockDeduction::with('items.product')
            ->where('uuid', $uuid)
            ->firstOrFail();

        // 3️⃣ Vérifier que la déduction est encore en brouillon
        if ($deduction->status !== 'draft') {
            return response()->json([
                'status'  => 'error',
                'message' => 'Cette déduction de stocks a déjà été validée ou annulée.'
            ], 403);
        }

        DB::beginTransaction();

        try {
            $insufficientProducts = [];

            // 4️⃣ Vérifier tous les items pour le stock
            foreach ($deduction->items as $item) {
                $productPoint = ProductPoint::firstOrCreate(
                    [
                        'produit_uuid' => $item->product_uuid,
                        'point_uuid'   => $deduction->warehouse_uuid,
                    ],
                    ['quantity' => 0]
                );

                if ($productPoint->quantity < $item->quantity) {
                    $insufficientProducts[] = [
                        'product'   => $item->product->name ?? $item->product_uuid,
                        'required'  => $item->quantity,
                        'available' => $productPoint->quantity,
                    ];
                }
            }

            // 5️⃣ Si certains produits sont insuffisants, retourner la liste
            if (!empty($insufficientProducts)) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Stock insuffisant pour certains produits.',
                    'details' => $insufficientProducts
                ], 422);
            }

            // 6️⃣ Déduire les quantités maintenant que tout est OK
            foreach ($deduction->items as $item) {
                $productPoint = ProductPoint::firstOrCreate(
                    [
                        'produit_uuid' => $item->product_uuid,
                        'point_uuid'   => $deduction->warehouse_uuid,
                    ],
                    ['quantity' => 0]
                );
                $productPoint->decrement('quantity', $item->quantity);
            }

            // 7️⃣ Mettre à jour le statut de la déduction
            $deduction->update([
                'status'       => 'validated',
                'validated_by' => $auth->id,
                'updated_by'   => $auth->id,
                'validated_at' => now(),
            ]);

            DB::commit();

            return response()->json([
                'status'  => 'success',
                'message' => 'Déduction de stock validée avec succès.',
                'data'    => $deduction->load('items.product'),
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'status'  => 'error',
                'message' => 'Erreur lors de la validation de la déduction de stock.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }







    //
}
