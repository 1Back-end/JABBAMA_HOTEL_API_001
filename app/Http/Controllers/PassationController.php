<?php

namespace App\Http\Controllers;

use App\Models\Passation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * @permission_category Gestion des passations de stocks entre agents
 */
class PassationController extends Controller
{

    /**
     * Display a listing of the resource.
     * @permission PassationController::store
     * @permission_desc Afficher la liste des passations de stocks entre agents
     */
    public function index(Request $request)
    {
        $auth = auth()->user();
        $perPage = $request->input('limit', 25);
        $page = $request->input('page', 1);

        $query = Passation::with([
            'items.product',
            'agentFrom',
            'agentTo',
            'warehouse',
            'creator',
            'updater'
        ]);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // 🔹 Filtrage par période
        if ($request->filled('start_date') && $request->filled('end_date')) {
            $query->whereBetween('created_at', [$request->start_date, $request->end_date]);
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
     * @permission PassationController::index
     * @permission_desc Effectuer une passation de stocks entre agents
     */
    public function store(Request $request)
    {
        $auth = auth()->user();

        // Validation
        $validated = $request->validate([
            'agent_to_id' => 'nullable|exists:users,id',
            'warehouse_uuid' => 'required|exists:warehouses,uuid',

            'items' => 'required|array|min:1',
            'items.*.product_uuid' => 'required|exists:produits,uuid',
            'items.*.quantity_sent' => 'required|numeric|min:0.001',
        ], [
            'warehouse_uuid.required' => 'L’entrepôt est obligatoire.',
            'items.required' => 'Vous devez ajouter au moins un article.',
            'items.*.product_uuid.exists' => 'Un produit est invalide.',
            'items.*.quantity_sent.min' => 'La quantité minimale est 0,001.',
        ]);

        // Ajout automatique de l’initiateur
        $validated['agent_from_id'] = $auth->id;
        $validated['created_by'] = $auth->id;
        $validated['status'] = 'pending';
        $validated['agent_to_id'] = $validated['agent_to_id'] ?? null;

        DB::beginTransaction();

        try {
            // Création de la passation
            $passation = Passation::create([
                'agent_from_id' => $validated['agent_from_id'],
                'agent_to_id' => $validated['agent_to_id'],
                'warehouse_uuid' => $validated['warehouse_uuid'],
                'status' => 'pending',
                'created_by' => $validated['created_by'],
            ]);

            // Ajout des articles envoyés
            foreach ($validated['items'] as $item) {
                $passation->items()->create([
                    'product_uuid' => $item['product_uuid'],
                    'quantity_sent' => $item['quantity_sent'],
                    'quantity_counted' => null,
                ]);
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Passation initiée avec succès.',
                'passation' => $passation->load('items.product', 'agentFrom', 'agentTo', 'warehouse'),
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
    public function update(Request $request, Passation $passation)
    {
        $auth = auth()->user();

        // Validation
        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.id' => 'required|exists:passation_items,id',
            'items.*.quantity_counted' => 'required|numeric|min:0',
        ], [
            'items.required' => 'Vous devez fournir les articles pour la validation.',
            'items.*.quantity_counted.required' => 'La quantité comptée est obligatoire.',
            'items.*.quantity_counted.min' => 'La quantité comptée doit être positive.',
        ]);

        DB::beginTransaction();

        try {
            $hasDifference = false;

            // Mise à jour des items
            foreach ($validated['items'] as $itemData) {

                $item = $passation->items()->find($itemData['id']);

                if (!$item) {
                    continue;
                }

                // Vérifier s'il existe un écart
                if ($itemData['quantity_counted'] != $item->quantity_sent) {
                    $hasDifference = true;
                }

                // Mise à jour des quantités comptées
                $item->update([
                    'quantity_counted' => $itemData['quantity_counted']
                ]);
            }

            // Déterminer le statut final
            $newStatus = $hasDifference ? 'partially_validated' : 'validated';

            // Mise à jour principale
            $passation->update([
                'status' => $newStatus,
                'validated_by' => $auth->id,
                'validated_at' => now(),
            ]);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => $newStatus === 'validated'
                    ? 'Passation validée sans écart.'
                    : 'Passation validée avec écarts.',
                'passation' => $passation->load('items.product', 'agentFrom', 'agentTo', 'warehouse')
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            \Log::error('Erreur validation passation : '.$e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Une erreur est survenue lors de la validation.',
                'details' => $e->getMessage()
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
            'items.product',
            'agentFrom',
            'agentTo',
            'warehouse',
            'creator',
            'updater'
        ])->where('uuid', $uuid)->first();

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




    //
}
