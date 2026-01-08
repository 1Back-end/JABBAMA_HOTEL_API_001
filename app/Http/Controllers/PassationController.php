<?php

namespace App\Http\Controllers;

use App\DTO\PassationsFilterData;
use App\DTO\StockAdjustmentFilterData;
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
 * @permission_category Gestion des passations de stocks entre agents
 */
class PassationController extends Controller
{

    /**
     * Display a listing of the resource.
     * @permission PassationController::index
     * @permission_desc Afficher la liste des passations de stocks
     */
    public function index(Request $request)
    {
        $auth = auth()->user();
        $perPage = $request->input('limit', 25);
        $page = $request->input('page', 1);

        $query = Passation::with([
            'agentFrom',
            'agentTo',
            'warehouse',
            'creator',
            'updater',
            'validator',
            'rejector',
            'cancellor',
            'managers'
        ]);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('agent_from_id')) {
            $query->where('agent_from_id', $request->agent_from_id);
        }
        if ($request->filled('warehouse_uuid')) {
            $query->where('warehouse_uuid', $request->warehouse_uuid);
        }
        if ($request->filled('start_date') && $request->filled('end_date')) {
            $start = \Illuminate\Support\Carbon::parse($request->start_date)->startOfDay();
            $end = Carbon::parse($request->end_date)->endOfDay();

            $query->whereBetween('created_at', [$start, $end]);
        }

        if (!$auth->hasRole(['SUPER_ADMIN']) && !$auth->can('view_all_passations')) {
            // Tous les autres utilisateurs voient seulement leurs propres créations ou les passations dont ils sont managers
            $query->where(function($q) use ($auth) {
                $q->where('created_by', $auth->id)
                    ->orWhereHas('managers', function($q2) use ($auth) {
                        $q2->where('manager_id', $auth->id);
                    });
            });
        }

        if ($search = trim($request->input('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('uuid', 'like', "%{$search}%")
                    ->orWhere('status', 'like', "%{$search}%")
                    ->orWhere('reference', 'like', "%{$search}%")

                    // 🔹 Entrepôts
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

                    // 🔹 Créateur
                    ->orWhereHas('creator', function ($qc) use ($search) {
                        $qc->where('nom_utilisateur', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
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
        $data = $query->latest()->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data'         => $data->items(),
            'current_page' => $data->currentPage(),
            'last_page'    => $data->lastPage(),
            'total'        => $data->total(),
        ]);

    }


    /**
     * Display a listing of the resource.
     * @permission PassationController::store
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

        // 🔥 Récupérer les produits depuis produit_point (pivot)
        $products = $warehouse->products()
            ->wherePivot('is_active', true)
            ->get();

        // 🔥 Total stock = somme des quantités de product_point
        $totalStock = $products->sum(fn ($p) => $p->pivot->quantity);

        DB::beginTransaction();
        try {

            // 1️⃣ Créer la passation
            $passation = Passation::create([
                'agent_from_id' => $auth->id,
                'warehouse_uuid' => $warehouse->uuid,
                'status' => 'pending',
                'quantity_sent' => $totalStock,
                'created_by' => $auth->id,
            ]);

            // 2️⃣ Ajouter les produits de product_point
            foreach ($products as $product) {
                PassationItem::create([
                    'passation_uuid' => $passation->uuid,
                    'product_uuid' => $product->uuid,
                    'quantity_sent' => $product->pivot->quantity, // quantité réelle de produit_point
                    'quantity_counted' => 0,
                    'difference' => 0,
                    'created_by' => $auth->id,
                    'status' => 'pending',
                ]);
            }

            // 3️⃣ Lier les managers à la passation
            foreach ($managers as $manager) {
                if ($manager->id == $auth->id) {
                    continue; // ne pas réassigner à l’agent créateur
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
     * @permission_desc Modifier une passation de stocks
     */
    public function update(Request $request, string $uuid)
    {
        $auth = auth()->user();

        // Validation
        $validated = $request->validate([
            'warehouse_uuid' => 'required|exists:warehouses,uuid',
        ]);

        $passation = Passation::where('uuid', $uuid)->firstOrFail();

        // Entrepôt ciblé
        $warehouse = Warehouse::where('uuid', $validated['warehouse_uuid'])->firstOrFail();

        // Récupérer les produits du pivot produit_point
        $products = $warehouse->products()
            ->wherePivot('is_active', true)
            ->get();

        // Nouveau total stock
        $totalStock = $products->sum(fn ($p) => $p->pivot->quantity);

        DB::beginTransaction();
        try {

            // 1️⃣ Mise à jour de la passation
            $passation->update([
                'warehouse_uuid' => $warehouse->uuid,
                'quantity_sent' => $totalStock,
                'updated_by' => $auth->id,
                'status' => 'pending',
            ]);

            // 2️⃣ Supprimer tous les anciens items pour recréer proprement
            PassationItem::where('passation_uuid', $passation->uuid)->delete();

            // 3️⃣ Recréer les items avec les données actualisées
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
     * @permission_desc Afficher les détails d'une passation de stocks
     */
    public function show($uuid)
    {
        // Récupérer la passation avec ses relations
        $passation = Passation::with([
            'items',
            'items.product',
            'agentFrom',
            'agentTo',
            'warehouse',
            'creator',
            'updater',
            'managers',
            'items.validated'
        ])
            ->where('uuid', $uuid)
            ->firstOrFail();

        if (!$passation) {
            return response()->json([
                'status' => 'error',
                'message' => 'Passation introuvable.',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'passation' => $passation,
        ]);
    }

    /**
     * Display a listing of the resource.
     * @permission PassationController::validate_passations
     * @permission_desc Valider une passation de stocks
     */
    public function validate_passations(Request $request, string $uuid)
    {
        $auth = auth()->user();

        // 🔹 Validation
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

                $item->update([
                    'quantity_counted' => $qteCounted,
                    'difference' => $difference,
                    'status' => $difference === 0 ? 'ok' : 'in_discuss',
                    'validated_at' => now(),
                    'validated_by' => $auth->id,
                    'updated_by' => $auth->id,
                ]);
            }

            // 🔹 STATUT UNIQUE DE LA PASSATION
            $passationStatus = 'in_discuss';

            $passation->update([
                'status' => $passationStatus,
                'validated_at' => now(),
                'validated_by' => $auth->id,
                'updated_by' => $auth->id,
                'reason_validated' => 'Validation par comptage',
            ]);

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
     * @permission PassationController::cancel_passations
     * @permission_desc Annuler une passation de stocks
     */
    public function cancel_passations(Request $request, string $uuid){
        $auth = auth()->user();

        $validated = $request->validate([
            'reason_cancelled' => 'required|string|min:3',
        ],[
            'reason_cancelled.required' => 'Le motif du rejet est obligatoire.',
        ]);
        $passation = Passation::findOrFail($uuid);
        $passation->update([
            'status' => 'cancel',
            'updated_by' => $auth->id,
            'cancelled_by' => $auth->id,
            'reason_cancelled' => $validated['reason_cancelled'],
            'cancelled_at' => now(),
        ]);

        return response()->json([
            'message' => "La passation de stocks a été annulée avec succès.",
            'passation' => $passation
        ]);
    }


    /**
     * Display a listing of the resource.
     * @permission PassationController::reject_passations
     * @permission_desc Rejetter une passation de stocks
     */
    public function reject_passations(Request $request, string $uuid)
    {
        $auth = auth()->user();

        $validated = $request->validate([
            'reason_reject' => 'required|string|min:3',
        ],[
            'reason_reject.required' => 'Le motif du rejet est obligatoire.',
        ]);

        $passation = Passation::where('uuid', $uuid)->firstOrFail();

        DB::beginTransaction();

        $passation->update([
            'status'         => 'rejected',
            'rejected_at'    => now(),
            'rejected_by'    => $auth->id,
            'updated_by'     => $auth->id,
            'reason_reject'  => $validated['reason_reject'],
        ]);

        DB::commit();

        return response()->json([
            'status'  => 'success',
            'message' => 'Passation rejetée avec succès.',
            'passation' => $passation->load('agentFrom', 'agentTo', 'warehouse'),
        ]);
    }

    /**
     * Display a listing of the resource.
     * @permission PassationController::destroy
     * @permission_desc Supprimer une passation de stocks
     */
    public function destroy(Request $request, string $uuid)
    {
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

            // Chercher si le document existe déjà
            $pdf = PdfDocument::where('order_uuid', $passations->uuid)
                ->where('name', 'DETAILS-ORDERS')
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
     * @permission_desc Valider une passation de stocks par le SUPER ADMIN
     */
    public function validate_passations_by_admin(Request $request, string $uuid)
    {
        $auth = auth()->user();

        // 🔐 Validation mot de passe
        $request->validate([
            'password' => 'required|string'
        ]);

        if (!Hash::check($request->password, $auth->password)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Mot de passe incorrect.'
            ], 422);
        }

        // 🔎 Récupération passation avec items
        $passation = Passation::with('items')
            ->where('uuid', $uuid)
            ->firstOrFail();

        // 🔹 Vérifier s'il existe un écart
        $hasGap = $passation->items
                ->where('difference', '>', 0)
                ->count() > 0;

        // 🔹 Statut final décidé par le super admin
        $finalStatus = $hasGap ? 'with_gap' : 'no_gap';

        // ✅ Mise à jour finale
        $passation->update([
            'status'       => $finalStatus,
            'approved_by'  => $auth->id,
            'approved_at'  => now(),
            'updated_by'   => $auth->id,
            'closed_at'    => now(),
        ]);

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
    }


    /**
     * Display a listing of the resource.
     * @permission PassationController::print_passations_by_agents_uuid
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
            $start_date = \Illuminate\Support\Carbon::parse($request->input('start_date'))->startOfDay();
            $end_date = Carbon::parse($request->input('end_date'))->endOfDay();
            // Récupération des passations liées au manager et en discussion
            $passations = Passation::with([
                'agentFrom',
                'agentTo',
                'warehouse',
                'creator',
                'updater',
                'validator',
                'rejector',
                'cancellor',
                'managers',
                'items' => function($q) {
                    $q->where('status', 'in_discuss'); // ne récupérer que les items en discussion
                }
            ])
                ->where('agent_from_id', $agentFromId)
                ->whereHas('items', function ($q) {
                    $q->where('status', 'in_discuss');
                })
                ->when($start_date && $end_date, function($q) use ($start_date, $end_date) {
                    $q->whereBetween('created_at', [$start_date, $end_date]); // Filtre sur la passation
                })
                ->get();


            if ($passations->isEmpty()) {
                return response()->json([
                    'message' => 'Aucune passation avec écart trouvée pour cet agent.'
                ], 404);
            }

            // Récupérer les infos du manager
            $manager = User::find($agentFromId);

            // Nom du fichier
            $fileName = 'ECARTS-PASSATIONS-' . strtoupper($manager->nom_utilisateur) . '-' . now()->format('YmdHis') . '.pdf';
            $folderPath = 'storage/passations-managers/' . $agentFromId;
            $filePath   = $folderPath . '/' . $fileName;

            if (!is_dir($folderPath)) {
                mkdir($folderPath, 0755, true);
            }

            $data = [
                'manager'    => $manager,
                'passations' => $passations,
                'start_date' => $start_date ? $start_date->format('d/m/Y') : null,
                'end_date'   => $end_date ? $end_date->format('d/m/Y') : null,
            ];

            // Footer optionnel
            $footer = 'pdfs.reports.factures.footer';

            save_browser_shot_pdf(
                view: 'pdfs.passations.passations_by_managers',
                data: $data,
                folderPath: $folderPath,
                path: $filePath,
                margins: [10, 10, 10, 10],
                footer: $footer
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

    /**
     * Display a listing of the resource.
     * @permission PassationController::export_passations_stocks
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








    //
}
