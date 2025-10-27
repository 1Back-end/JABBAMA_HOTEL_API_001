<?php

namespace App\Http\Controllers;


use App\Exports\SuppliersExport;
use App\Models\DeletionCode;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;

class SupplierController extends Controller
{
    /**
     * Display a listing of the resource.
     * @permission SupplierController::index
     * @permission_desc Afficher la liste des fournisseurs
     */
    public function index(Request $request)
    {
        // Pagination
        $perPage = $request->input('limit', 25);
        $page = $request->input('page', 1);

        // Query de base avec relations
        $query = Supplier::with(['creator', 'updater'])
            ->when($request->has('is_active'), function ($query) use ($request) {
                $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
            });

        // Recherche
        if ($search = trim($request->input('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('ref', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone_number', 'like', "%{$search}%")
                    ->orWhere('phone_number_2', 'like', "%{$search}%")
                    ->orWhere('address', 'like', "%{$search}%")
                    ->orWhere('cni_number', 'like', "%{$search}%")
                    ->orWhere('company_name', 'like', "%{$search}%")
                    ->orWhere('company_email', 'like', "%{$search}%")
                    ->orWhere('company_phone', 'like', "%{$search}%");
            });
        }

        // Pagination et tri par date de création
        $suppliers = $query->latest()->paginate($perPage, ['*'], 'page', $page);

        // Réponse JSON
        return response()->json([
            'data'         => $suppliers->items(),
            'current_page' => $suppliers->currentPage(),
            'last_page'    => $suppliers->lastPage(),
            'total'        => $suppliers->total(),
        ]);
    }

    /**
     * Display a listing of the resource.
     * @permission SupplierController::store
     * @permission_desc Créer des fournisseurs
     */

    public function store(Request $request)
    {
        $auth = auth()->user();

        try {
            // Validation des données
            $validator = $request->validate([
                'first_name'     => 'required|string|max:255',
                'last_name'      => 'nullable|string|max:255',
                'email'          => 'nullable|email|unique:suppliers,email',
                'phone_number'   => 'required|string|unique:suppliers,phone_number',
                'phone_number_2' => 'nullable|string|unique:suppliers,phone_number_2',
                'address'        => 'nullable|string|max:500',
                'cni_number'     => 'nullable|string|unique:suppliers,cni_number',
                'description'    => 'nullable|string',
                'company_name'   => 'required|string|max:255|unique:suppliers,company_name',
                'company_email'  => 'nullable|email|unique:suppliers,company_email',
                'company_phone'  => 'required|string|unique:suppliers,company_phone',
                'is_active'      => 'nullable|boolean',
            ], [
                'first_name.required'   => 'Le prénom est obligatoire.',
                'phone_number.required' => 'Le numéro de téléphone principal est obligatoire.',
                'email.email'           => 'L\'email doit être valide.',
                'phone_number.unique'   => 'Ce numéro de téléphone est déjà utilisé.',
                'email.unique'          => 'Cet email est déjà utilisé.',
                'company_email.unique'  => 'L\'email de la société est déjà utilisé.',
                'cni_number.unique'     => 'Ce numéro de CNI est déjà utilisé.',
                'company_phone.unique'  => 'Le numéro de téléphone de la société est déjà utilisé.',
                'company_name.unique' => 'Le nom de la société est déjà utilisé.'
            ]);

            // Ajouter l'auteur
            $validator['created_by'] = $auth->id;

            // Créer le fournisseur
            $supplier = Supplier::create($validator);

            return response()->json([
                'success' => true,
                'data'    => $supplier,
                'message' => 'Fournisseur créé avec succès.'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur est survenue lors de la création du fournisseur.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }



    /**
     * Display a listing of the resource.
     * @permission SupplierController::update
     * @permission_desc Mettre à jour un fournisseur
     */
    public function update(Request $request, $uuid)
    {
        $auth = auth()->user();

        // Récupérer le fournisseur
        $supplier = Supplier::findOrFail($uuid);

        try {
            $validator = $request->validate([
                'first_name'     => 'required|string|max:255',
                'last_name'      => 'nullable|string|max:255',
                'email'          => 'nullable|email|unique:suppliers,email,' . $supplier->uuid . ',uuid',
                'phone_number'   => 'required|string|unique:suppliers,phone_number,' . $supplier->uuid . ',uuid',
                'phone_number_2' => 'nullable|string|unique:suppliers,phone_number_2,' . $supplier->uuid . ',uuid',
                'address'        => 'nullable|string|max:500',
                'cni_number'     => 'nullable|string|unique:suppliers,cni_number,' . $supplier->uuid . ',uuid',
                'description'    => 'nullable|string',
                'company_name'   => 'required|string|max:255|unique:suppliers,company_name,' . $supplier->uuid . ',uuid',
                'company_email'  => 'nullable|email|unique:suppliers,company_email,' . $supplier->uuid . ',uuid',
                'company_phone'  => 'nullable|string|unique:suppliers,company_phone,' . $supplier->uuid . ',uuid',
                'is_active'      => 'nullable|boolean',
            ], [
                'first_name.required'   => 'Le prénom est obligatoire.',
                'phone_number.required' => 'Le numéro de téléphone principal est obligatoire.',
                'email.email'           => 'L\'email doit être valide.',
                'phone_number.unique'   => 'Ce numéro de téléphone est déjà utilisé.',
                'email.unique'          => 'Cet email est déjà utilisé.',
                'company_email.unique'  => 'L\'email de la société est déjà utilisé.',
                'cni_number.unique'     => 'Ce numéro de CNI est déjà utilisé.',
                'company_phone.unique'  => 'Le numéro de téléphone de la société est déjà utilisé.',
                'company_name.unique' => 'Le nom de la société est déjà utilisé.'
            ]);

            // Ajouter l'updateur
            $validator['updated_by'] = $auth->id;

            // Mise à jour
            $supplier->update($validator);

            return response()->json([
                'success' => true,
                'data'    => $supplier,
                'message' => 'Fournisseur mis à jour avec succès.'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur est survenue lors de la mise à jour du fournisseur.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Display a listing of the resource.
     * @permission SupplierController::show
     * @permission_desc Afficher les détails d'un fournisseur
     */
    public function show(string $uuid)
    {
        try {
            $supplier = Supplier::with(['creator', 'updater'])->findOrFail($uuid);

            return response()->json([
                'success' => true,
                'data'    => $supplier,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Fournisseur non trouvé.',
                'error'   => $e->getMessage(),
            ], 404);
        }
    }


    /**
     * Display a listing of the resource.
     * @permission SupplierController::update_status
     * @permission_desc Activer / Désactiver le compte d'un fournisseur
     */
    public function update_status(Request $request, $uuid)
    {
        $auth = auth()->user();

        try {
            // Valider la requête
            $request->validate([
                'is_active' => 'required|boolean',
            ], [
                'is_active.required' => 'Le statut est obligatoire.',
                'is_active.boolean'  => 'Le statut doit être true ou false.',
            ]);

            $supplier = Supplier::findOrFail($uuid);

            $supplier->is_active = $request->is_active;
            $supplier->updated_by = $auth->id;
            $supplier->save();

            $message = $supplier->is_active ? 'Fournisseur activé avec succès.' : 'Fournisseur désactivé avec succès.';

            return response()->json([
                'success' => true,
                'data'    => $supplier,
                'message' => $message,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Une erreur est survenue lors de la mise à jour du statut du fournisseur.',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function send_user_code_otp(Request $request)
    {
        $auth = auth()->user();

        // Générer un code numérique de 8 chiffres
        $code = (string) mt_rand(10000000, 99999999);

        // Définir la date d'expiration
        $expires_at = now()->addHours(24);

        $deletionCode = DeletionCode::create([
            'user_id'     => $auth->id,
            'target_type' => 'supplier',
            'target_uuid' => $request->target_uuid,
            'code'        => $code,
            'used'        => false,
            'expires_at'  => $expires_at,
            'created_by'  => $auth->id,
            'updated_by'  => $auth->id,
        ]);

        Mail::send('emails.deletion_code', [
            'code'       => $code,
            'expires_at' => $expires_at
        ], function ($message) use ($auth) {
            $message->to($auth->email)
                ->subject('Code de validation pour suppression');
        });

        return response()->json([
            'success' => true,
            'message' => 'Code de validation pour suppression envoyé à ' . $auth->email,
            'data'  =>  $deletionCode
        ]);
    }



    /**
     * Display a listing of the resource.
     * @permission SupplierController::destroy
     * @permission_desc Suppression des  fournisseurs
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

        $supplier = Supplier::findOrFail($uuid);

        // Vérifier si des commandes sont liées
        if ($supplier->orders()->exists()) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Impossible de supprimer ce fournisseur : des commandes lui sont associées.'
            ], 422);
        }

        // Supprimer le fournisseur avec forceDelete
        $supplier->forceDelete();

        return response()->json([
            'success' => true,
            'message' => 'Fournisseur supprimé avec succès.'
        ]);
    }





}
