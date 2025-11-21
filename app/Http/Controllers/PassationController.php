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

        // Validation avec messages personnalisés
        $validated = $request->validate([
            'agent_from_id' => 'required|exists:users,id',
            'agent_to_id' => 'nullable|exists:users,id',
            'warehouse_uuid' => 'nullable|exists:warehouses,uuid',
            'items' => 'required|array|min:1',
            'items.*.product_uuid' => 'required|exists:produits,uuid',
            'items.*.quantity_sent' => 'required|numeric|min:0.001',
        ], [
            'agent_from_id.required' => 'L’agent transmetteur est obligatoire.',
            'agent_from_id.exists' => 'L’agent transmetteur sélectionné est invalide.',
            'agent_to_id.exists' => 'L’agent destinataire sélectionné est invalide.',
            'warehouse_uuid.exists' => 'L’entrepôt sélectionné est invalide.',
            'items.required' => 'Vous devez ajouter au moins un article pour la passation.',
            'items.array' => 'Les articles doivent être fournis sous forme de tableau.',
            'items.min' => 'Au moins un article est requis pour la passation.',
            'items.*.product_uuid.required' => 'Chaque article doit avoir un produit associé.',
            'items.*.product_uuid.exists' => 'Le produit sélectionné pour un article est invalide.',
            'items.*.quantity_sent.required' => 'La quantité envoyée pour chaque article est obligatoire.',
            'items.*.quantity_sent.numeric' => 'La quantité envoyée doit être un nombre.',
            'items.*.quantity_sent.min' => 'La quantité envoyée doit être au moins de 0,001.'
        ]);

        // Ajouter l'utilisateur créateur
        $validated['created_by'] = $auth->id;

        // Démarrer la transaction
        DB::beginTransaction();
        try {
            // Créer la passation
            $passation = Passation::create($validated);

            // Créer les items associés
            foreach ($request->items as $item) {
                $passation->items()->create([
                    'product_uuid' => $item['product_uuid'],
                    'quantity_sent' => $item['quantity_sent'],
                ]);
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Passation créée avec succès.',
                'passation' => $passation->load('items.product', 'agentFrom', 'agentTo', 'warehouse')
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            \Log::error('Erreur création passation : ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Une erreur est survenue lors de la création de la passation.',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    //
}
