<?php

namespace App\Http\Controllers;


use App\Exports\PurchaseOrdersExport;
use App\Exports\SuppliersExport;
use App\Models\DeletionCode;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;


/**
 * @permission_category Gestion des fournisseurs
 * @permission_module Gestion des stocks
 * @code_module STOCK
 */

class SupplierController extends Controller
{
    /**
     * Display a listing of the resource.
     * @permission SupplierController::index
     * @code STOCK
     * @permission_desc Afficher la liste des fournisseurs
     */
    public function index(Request $request)
    {

        $perPage = $request->input('limit', 25);
        $page = $request->input('page', 1);


        $query = Supplier::with(['creator', 'updater'])
            ->when($request->has('is_active'), function ($query) use ($request) {
                $query->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN));
            });


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


        $suppliers = $query->latest()->paginate($perPage, ['*'], 'page', $page);

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
     * @code STOCK
     * @permission_desc Créer des fournisseurs
     */

    public function store(Request $request)
    {
        $auth = auth()->user();

        try {
            $validated = $request->validate([
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
                'first_name.required'    => 'Le prénom est obligatoire.',
                'phone_number.required'  => 'Le numéro de téléphone principal est obligatoire.',
                'email.email'            => 'L\'adresse email du contact doit être valide.',
                'email.unique'           => 'Cet email est déjà utilisé.',
                'phone_number.unique'    => 'Ce numéro de téléphone est déjà utilisé.',
                'phone_number_2.unique'  => 'Le second numéro de téléphone est déjà utilisé.',
                'cni_number.unique'      => 'Ce numéro de CNI est déjà utilisé.',
                'company_name.required'  => 'Le nom de la société est obligatoire.',
                'company_name.unique'    => 'Le nom de la société est déjà utilisé.',
                'company_email.email'    => 'L\'adresse email de la société doit être valide.',
                'company_email.unique'   => 'L\'email de la société est déjà utilisé.',
                'company_phone.required' => 'Le numéro de téléphone de la société est obligatoire.',
                'company_phone.unique'   => 'Le numéro de téléphone de la société est déjà utilisé.',
            ]);
            $validated['created_by'] = $auth->id;

            $supplier = Supplier::create($validated);

            return response()->json([
                'success' => true,
                'data'    => $supplier,
                'message' => 'Fournisseur créé avec succès.'
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'validation_error',
                'errors' => $e->errors(),
            ], 422);

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
     * @code STOCK
     * @permission_desc Mettre à jour un fournisseur
     */
    public function update(Request $request, $uuid)
    {
        $auth = auth()->user();

        $supplier = Supplier::findOrFail($uuid);

        try {

            $validated = $request->validate([
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
                'company_phone'  => 'required|string|unique:suppliers,company_phone,' . $supplier->uuid . ',uuid',
                'is_active'      => 'nullable|boolean',
            ], [
                'first_name.required'    => 'Le prénom est obligatoire.',
                'phone_number.required'  => 'Le numéro de téléphone principal est obligatoire.',
                'email.email'            => 'L\'adresse email du contact doit être valide.',
                'email.unique'           => 'Cet email est déjà utilisé.',
                'phone_number.unique'    => 'Ce numéro de téléphone est déjà utilisé.',
                'phone_number_2.unique'  => 'Le second numéro de téléphone est déjà utilisé.',
                'cni_number.unique'      => 'Ce numéro de CNI est déjà utilisé.',
                'company_name.required'  => 'Le nom de la société est obligatoire.',
                'company_name.unique'    => 'Le nom de la société est déjà utilisé.',
                'company_email.email'    => 'L\'adresse email de la société doit être valide.',
                'company_email.unique'   => 'L\'email de la société est déjà utilisé.',
                'company_phone.required' => 'Le numéro de téléphone de la société est obligatoire.',
                'company_phone.unique'   => 'Le numéro de téléphone de la société est déjà utilisé.',
            ]);

            $validated['updated_by'] = $auth->id;


            $supplier->update($validated);

            return response()->json([
                'success' => true,
                'data'    => $supplier->fresh(),
                'message' => 'Fournisseur mis à jour avec succès.'
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'status' => 'validation_error',
                'errors' => $e->errors(),
            ], 422);

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
     * @code STOCK
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
     * @code STOCK
     * @permission_desc Activer / Désactiver le compte d'un fournisseur
     */
    public function update_status(Request $request, $uuid)
    {
        $auth = auth()->user();

        try {
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

    /**
     * Display a listing of the resource.
     * @permission SupplierController::export_suppliers
     * @code STOCK
     * @permission_desc Exporer les fournisseurs au format Excel
     */
    public function export_suppliers()
    {
        $fileName = 'LISTE-DES-FOURNISSEURS-' . Carbon::now()->format('d-m-Y-H-i-s') . '.xlsx';

        Excel::store(new SuppliersExport(), $fileName, 'exportsuppliers');

        return response()->json([
            "message" => "Exportation des données effectuée avec succès",
            "filename" => $fileName,
            "url" => Storage::disk('exportsuppliers')->url($fileName)
        ]);
    }



}
