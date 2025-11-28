<?php

namespace App\Http\Controllers;

use App\Models\Passation;
use App\Models\PassationItem;
use App\Models\PdfDocument;
use App\Models\PurchaseOrder;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Mockery\Generator\StringManipulation\Pass\Pass;

/**
 * @permission_category Gestion des passations de stocks entre agents
 */
class PassationController extends Controller
{

    /**
     * Display a listing of the resource.
     * @permission PassationController::index
     * @permission_desc Afficher la liste des passations de stocks entre agents
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

        // 🔹 Filtrage par période
        if ($request->filled('start_date') && $request->filled('end_date')) {
            $query->whereBetween('created_at', [$request->start_date, $request->end_date]);
        }

        if (!$auth->hasRole('SUPER_ADMIN')) {
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
     * @permission_desc Effectuer une passation de stocks entre agents
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

        $products = $warehouse->products;

        DB::beginTransaction();
        try {

            // 1️⃣ Créer une seule passation
            $passation = Passation::create([
                'agent_from_id' => $auth->id,
                'warehouse_uuid' => $warehouse->uuid,
                'status' => 'pending',
                'quantity_sent' => $warehouse->total_stock,
                'created_by' => $auth->id,
            ]);

            // 2️⃣ Ajouter les produits
            foreach ($products as $product) {
                PassationItem::create([
                    'passation_uuid' => $passation->uuid,
                    'product_uuid' => $product->uuid,
                    'quantity_sent' => $product->stock_quantity,
                    'quantity_counted' => 0,
                    'difference' => 0,
                    'created_by' => $auth->id,
                    'status' => 'pending',
                ]);
            }

            // 3️⃣ Lier tous les managers dans la table pivot
            foreach ($managers as $manager) {
                if($manager->id == $auth->id){
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
     * @permission_desc Modifier une passation de stocks entre agents
     */
        public function update(Request $request, $uuid)
        {
            $auth = auth()->user();

            // Validation
            $validated = $request->validate([
                'warehouse_uuid' => 'required|exists:warehouses,uuid',
            ]);

            $passation = Passation::where('uuid', $uuid)->firstOrFail();

            $warehouse = Warehouse::where('uuid', $validated['warehouse_uuid'])->firstOrFail();
            $managers = $warehouse->managers;
            $products = $warehouse->products;

            if ($managers->isEmpty()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Aucun manager trouvé pour cet entrepôt.'
                ], 404);
            }

            DB::beginTransaction();

            try {
                /**
                 * 1️⃣ Supprimer les anciens items
                 */
                PassationItem::where('passation_uuid', $passation->uuid)->delete();

                /**
                 * 2️⃣ Supprimer les anciens managers
                 */
                DB::table('passation_managers')
                    ->where('passation_uuid', $passation->uuid)
                    ->delete();

                /**
                 * 3️⃣ Mettre à jour la passation
                 */
                $passation->update([
                    'warehouse_uuid' => $warehouse->uuid,
                    'quantity_sent' => $warehouse->total_stock,
                    'status' => 'pending',
                    'updated_by' => $auth->id,
                ]);

                /**
                 * 4️⃣ Recréer tous les nouveaux items
                 */
                foreach ($products as $product) {
                    PassationItem::create([
                        'passation_uuid' => $passation->uuid,
                        'product_uuid' => $product->uuid,
                        'quantity_sent' => $product->stock_quantity,
                        'quantity_counted' => 0,
                        'difference' => 0,
                        'status' => 'pending',
                        'created_by' => $auth->id,
                    ]);
                }

                /**
                 * 5️⃣ Réassigner les managers
                 */
                foreach ($managers as $manager) {

                    // éviter d'ajouter le créateur si c’est aussi un manager
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

                DB::commit();

                return response()->json([
                    'status' => 'success',
                    'message' => 'Passation mise à jour avec succès.',
                    'passation' => $passation->load('items', 'managers', 'warehouse'),
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
     * @permission_desc Details d'une passation de stocks entre agents
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
     * @permission PassationController::cancel_passations
     * @permission_desc Annulation d'une passation de stocks entre agents
     */
    public function cancel_passations(Request $request, string $uuid){
        $auth = auth()->user();
        $validated = $request->validate([
            'status' => 'required|in:cancel',
        ], [
            'status.required' => "Le statut est obligatoire.",
            'status.in' => "Le statut fourni est invalide.",
        ]);
        $passation = Passation::findOrFail($uuid);
        $passation->update([
            'status' => 'cancel',
            'updated_by' => auth()->id(),
            'cancelled_by' => auth()->id(),
            'reason_cancelled' => 'La passation de stocks a été annulée avec succès.',
            'cancelled_at' => now(),
        ]);

        return response()->json([
            'message' => "La passation de stocks a été annulée avec succès.",
            'passation' => $passation
        ]);
    }

    /**
     * Display a listing of the resource.
     * @permission PassationController::validate_passations
     * @permission_desc Validation d'une passation de stocks entre agents
     */
    public function validate_passations(Request $request, string $uuid)
    {
        $auth = auth()->user();

        // 🔹 Validation des données
        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.uuid' => 'required|exists:passation_items,uuid',
            'items.*.quantity_counted' => 'required|integer|min:0',
        ], [
            'items.required' => 'Les items sont obligatoires.',
            'items.*.quantity_counted.required' => 'La quantité comptée est obligatoire pour chaque item.',
        ]);

        DB::beginTransaction();

        try {
            $passation = Passation::with('items.product')->where('uuid', $uuid)->firstOrFail();

            foreach ($validated['items'] as $itemData) {
                $item = $passation->items->where('uuid', $itemData['uuid'])->first();
                if (!$item) continue;

                $qte_sent = (int) $item->quantity_sent;
                $qte_counted = (int) $itemData['quantity_counted'];

                if ($qte_counted > $qte_sent) {
                    return response()->json([
                        'status' => 'error',
                        'message' => "La quantité comptée pour l'article {$item->product?->name} ne peut pas être supérieure à la quantité envoyée.",
                    ], 400);
                }

                $difference = $qte_sent - $qte_counted;

                $status = $qte_counted === $qte_sent ? 'ok' : 'in_discuss';

                $item->update([
                    'quantity_counted' => $qte_counted,
                    'difference' => $difference,
                    'status' => $status,
                    'updated_by' => $auth->id,
                ]);
            }

            // 🔹 Mettre à jour le statut global de la passation
            $allOk = $passation->items->every(fn($i) => $i->status === 'ok');
            $passationStatus = $allOk ? 'closed' : 'in_discuss';

            $passation->update([
                'status' => $passationStatus,
                'validated_at' => now(),
                'validated_by' => $auth->id,
                'updated_by' => $auth->id,
                'reason_validated' => 'Validation par items.',
            ]);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Passation validée avec succès.',
                'passation' => $passation->load('items.product', 'agentFrom', 'agentTo', 'warehouse'),
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'status' => 'error',
                'message' => 'Une erreur est survenue lors de la validation de la passation.',
                'details' => $e->getMessage(),
            ], 500);
        }
    }





    /**
     * Display a listing of the resource.
     * @permission PassationController::reject_passations
     * @permission_desc Rejetion d'une passation de stocks entre agents
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
     * @permission_desc Suppression d'une passation de stocks entre agents
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

        $passation->forceDelete();
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
     * @permission_desc Validation de passations de stocks par le SUPER ADMIN
     */
    public function validate_passations_by_admin(Request $request, string $uuid){
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
        $passation = Passation::findOrFail($uuid);
        $passation->update([
            'status' => 'validated',
            'updated_by' => auth()->id(),
            'approved_by' => auth()->id(),
            'approved_at' => now(),
            'closed_at' => now()
        ]);

        return response()->json([
            'message' => "La passation a été validée avec succès.",
            'passation' => $passation->load('agentFrom', 'agentTo', 'warehouse'),
        ]);
    }







    //
}
