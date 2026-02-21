<?php

namespace App\Http\Controllers;

use App\Http\Requests\RegulationMethodRequest;
use App\Models\RegulationMethod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
/**
 *  @permission_category Gestion des modes de règlements
 * @permission_module Gestion du restaurant
 * /
 */
class RegulationMethodController extends Controller
{

    /**
     * @return JsonResponse
     *
     * @permission RegulationMethodController::index
     * @permission_desc Afficher la liste des modes de règlement
     */
    public function index()
    {
        return response()->json(
            RegulationMethod::with(['creator:id,nom_utilisateur','updater'])
                ->when(request()->input('active'), function ($query) {
                    $query->where('active', request()->input('active'));
                })
                ->get()
        );
    }

    /**
     * @param RegulationMethodRequest $request
     * @return JsonResponse
     *
     * @permission RegulationMethodController::store
     * @permission_desc Créer un mode de règlement
     */
    public function store(RegulationMethodRequest $request)
    {
        $auth = auth()->user();

        $data = array_merge($request->validated(), [
            'created_by' => $auth->id,
            'updated_by' => $auth->id,
        ]);

        RegulationMethod::create($data);

        return response()->json([
            'message' => __('Enregistrement effectué avec succès')
        ]);
    }

    /**
     * @param RegulationMethodRequest $request
     * @param RegulationMethod $regulationMethod
     * @return JsonResponse
     *
     * @permission RegulationMethodController::update
     * @permission_desc Modifier un mode de règlement
     */
    public function update(RegulationMethodRequest $request, RegulationMethod $regulationMethod)
    {
        $auth = auth()->user();

        $data = array_merge($request->validated(), [
            'updated_by' => $auth->id
        ]);
        $regulationMethod->update($data);

        return response()->json([
            'message' => 'Mise à jour effectuée avec succès'
        ]);
    }

    /**
     * @param RegulationMethod $regulationMethod
     * @return JsonResponse
     *
     * @permission RegulationMethodController::activate
     * @permission_desc Activer/Désactiver un mode de règlement
     */
    public function activate(RegulationMethod $regulationMethod)
    {
        $regulationMethod->active = !$regulationMethod->active;
        $regulationMethod->save();

        return response()->json([], 200);
    }
    //
}
