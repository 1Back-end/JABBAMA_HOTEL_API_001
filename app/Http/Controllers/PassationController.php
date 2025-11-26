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
            'cancellor'
        ]);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // 🔹 Filtrage par période
        if ($request->filled('start_date') && $request->filled('end_date')) {
            $query->whereBetween('created_at', [$request->start_date, $request->end_date]);
        }

        // 🔹 Gestion des accès selon les rôles
        if (!$auth->hasRole('SUPER_ADMIN')) {
            // 👉 Tous les autres utilisateurs (sauf Super Admin)
            $query->where('created_by', $auth->id);
        }
        // 👉 Le SUPER_ADMIN voit tout (aucune restriction)

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
            'agent_to_id' => 'required|exists:users,id',
            'warehouse_uuid' => 'required|exists:warehouses,uuid',
        ], [
            'warehouse_uuid.required' => 'L’entrepôt est obligatoire.',
            'agent_to_id.required' => 'Le manager recepteur est obligatoire.',
        ]);

        $warehouse = Warehouse::where('uuid', $request->warehouse_uuid)->firstOrFail();


        DB::beginTransaction();

        try {
            // Création de la passation
            $passation = Passation::create([
                'agent_from_id' => $auth->id,
                'agent_to_id' => $validated['agent_to_id'],
                'warehouse_uuid' => $validated['warehouse_uuid'],
                'status' => 'pending',
                'quantity_sent' => $warehouse->total_stock,
                'created_by' => $auth->id,
            ]);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Passation initiée avec succès.',
                'passation' => $passation->load('agentFrom', 'agentTo', 'warehouse'),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Erreur création passation : ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Une erreur est survenue lors de la création de la passation.',
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
        $passation = Passation::where('uuid',$uuid)->first();
        if(!$passation){
            return response()->json([
                'status' => 'error',
                'message' => 'Passation de stocks entre agents introuvable.',
            ]);
        }

        // Validation
        $validated = $request->validate([
            'agent_to_id' => 'required|exists:users,id',
            'warehouse_uuid' => 'required|exists:warehouses,uuid',
        ], [
            'warehouse_uuid.required' => 'L’entrepôt est obligatoire.',
            'agent_to_id.required' => 'Le manager recepteur est obligatoire.',
        ]);

        $warehouse = Warehouse::where('uuid', $request->warehouse_uuid)->firstOrFail();

        DB::beginTransaction();

        try {
            // Création de la passation
            $passation->update([
                'agent_from_id' => $auth->id,
                'agent_to_id' => $validated['agent_to_id'],
                'warehouse_uuid' => $validated['warehouse_uuid'],
                'status' => 'pending',
                'quantity_sent' => $warehouse->total_stock,
                'updated_by' => auth()->id(),
            ]);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Passation mise à jour avec succès.',
                'passation' => $passation->load('agentFrom', 'agentTo', 'warehouse'),
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Erreur création passation : ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Une erreur est survenue lors de la création de la passation.',
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
            'agentFrom',
            'agentTo',
            'warehouse',
            'creator',
            'updater'
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
            'data' => $passation,
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
            'quantity_counted' => 'required|integer|min:1',
        ],[
            'quantity_counted.required' => 'La quantité comptée est obligatoire.',
        ]);

        DB::beginTransaction();

        try {

            // 🔹 Récupération de la passation
            $passation = Passation::with('items')->where('uuid', $uuid)->firstOrFail();

            $qte_sent = (float) $passation->quantity_sent;
            $qte_counted = (float) $validated['quantity_counted'];

            // 🔹 Vérification logique
            if ($qte_counted > $qte_sent) {
                return response()->json([
                    'status' => 'error',
                    'message' => "La quantité comptée ne peut pas être supérieure à la quantité envoyée.",
                ], 400);
            }

            // 🔹 Calcul de la différence
            $difference = $qte_sent - $qte_counted;

            // 🔹 Mise à jour de la passation
            $passation->update([
                'status' => 'in_discuss',
                'quantity_counted' => $qte_counted,
                'difference' => $difference,
                'validated_at' => now(),
                'validated_by' => $auth->id,
                'updated_by' => $auth->id,
                'reason_validated' => "Passation validée avec succès.",
            ]);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Passation validée avec succès.',
                'passation' => $passation->load('agentFrom', 'agentTo', 'warehouse'),
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







    //
}
