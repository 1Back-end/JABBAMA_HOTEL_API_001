<?php

namespace App\Http\Controllers;

use App\Models\Passation;
use App\Models\PassationItem;
use App\Models\PurchaseOrder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            'items.product',
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

        DB::beginTransaction();

        try {
            // Création de la passation
            $passation = Passation::create([
                'agent_from_id' => $auth->id,
                'agent_to_id' => $validated['agent_to_id'],
                'warehouse_uuid' => $validated['warehouse_uuid'],
                'status' => 'pending',
                'created_by' => $auth->id,
            ]);

            // Ajout des articles envoyés
            foreach ($validated['items'] as $item) {
                $passation->items()->create([
                    'product_uuid' => $item['product_uuid'],
                    'quantity_sent' => $item['quantity_sent'],
                    'created_by' => $auth->id,
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

        DB::beginTransaction();

        try {
            // Création de la passation
            $passation->update([
                'agent_from_id' => $auth->id,
                'agent_to_id' => $validated['agent_to_id'],
                'warehouse_uuid' => $validated['warehouse_uuid'],
                'status' => 'pending',
                'updated_by' => auth()->id(),
            ]);

            $passation->items()->delete();
            // Ajout des articles envoyés
            foreach ($validated['items'] as $item) {
                $passation->items()->create([
                    'product_uuid' => $item['product_uuid'],
                    'quantity_sent' => $item['quantity_sent'],
                    'updated_by' => auth()->id(),
                ]);
            }

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Passation mise à jour avec succès.',
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

        // 🔹 Validation des données reçues
        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.uuid' => 'required|exists:passation_items,uuid',
            'items.*.quantity_counted' => 'required|numeric|min:0',
        ], [
            'items.required' => 'Les articles sont obligatoires.',
            'items.*.quantity_counted.required' => 'La quantité comptée est obligatoire.',
            'items.*.quantity_counted.min' => 'La quantité comptée doit être >= 0.',
        ]);

        // 🔹 Récupération de la passation
        $passation = Passation::with('items')->where('uuid', $uuid)->firstOrFail();

        DB::beginTransaction();

        try {
            foreach ($validated['items'] as $item) {

                $passationItem = PassationItem::where('uuid', $item['uuid'])->first();

                if (!$passationItem) continue;

                $quantitySent = floatval($passationItem->quantity_sent);
                $quantityCounted = floatval($item['quantity_counted']);

                // Calcul de la différence
                $difference = $quantitySent - $quantityCounted;

                // 🔹 Mise à jour de l’item
                $passationItem->update([
                    'quantity_counted' => $quantityCounted,
                    'difference' => $difference,
                    'updated_by' => $auth->id
                ]);
            }

            // 🔹 Mise à jour du statut
            $passation->update([
                'status' => 'in_discuss',
                'updated_by' => $auth->id,
                'validated_at' => now(),
                'reason_validated' => "Passation validée avec succès.",
                'validated_by' => auth()->id(),
            ]);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Passation validée avec succès.',
                'passation' => $passation->load('items.product', 'agentFrom', 'agentTo', 'warehouse')
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            \Log::error('Erreur validation passation : ' . $e->getMessage());

            return response()->json([
                'status' => 'error',
                'message' => 'Erreur lors de la validation de la passation.',
                'details' => $e->getMessage()
            ], 500);
        }
    }





    //
}
