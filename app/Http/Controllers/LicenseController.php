<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\License;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class LicenseController extends Controller
{

    /**
     * Récupérer les informations de la licence active pour l'utilisateur connecté
     */
    public function index(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Non authentifié.'
            ], 401);
        }

        $superAdmin = \App\Models\User::where('is_super_admin', true)->first();

        if (!$superAdmin) {
            return response()->json([
                'success' => false,
                'message' => 'Aucun super administrateur configuré dans le système.'
            ], 404);
        }

        $license = License::where('user_id', $superAdmin->id)
            ->where('status', 'ACTIVE')
            ->where('expires_at', '>', Carbon::now())
            ->first();

        if (!$license) {
            return response()->json([
                'success' => false,
                'error_code' => 'NO_ACTIVE_LICENSE',
                'message' => 'Aucune licence active trouvée.'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'format' => $license->format,
                'issuer' => $license->issuer,
                'client' => [
                    'id' => $license->client_external_id,
                    'name' => $license->client_name,
                    'email' => $license->client_email,
                ],
                'application' => [
                    'id' => $license->app_id,
                    'code' => $license->app_code,
                    'name' => $license->app_name,
                ],
                'license' => [
                    'reference' => $license->reference,
                    'license_key' => $license->license_key,
                    'status' => $license->status,
                    'jti' => $license->jti,
                ],
                'modules' => $license->modules,
                'module_entitlements' => $license->module_entitlements,
                'issued_at' => $license->issued_at,
                'expires_at' => $license->expires_at,
                'generated_at' => $license->generated_at,
            ]
        ], 200);
    }

    public function store(Request $request)
    {

        $request->validate([
            'certificate' => 'required|file|mimes:json,cer,txt',
        ]);

        try {
            $fileContent = file_get_contents($request->file('certificate')->getRealPath());
            $data = json_decode($fileContent, true);

            if (!isset($data['format']) || $data['format'] !== 'sgl-license-clear-v1') {
                return response()->json([
                    'success' => false,
                    'message' => 'Format de certificat invalide ou non reconnu.'
                ], 422);
            }

            $stockEntitlement = null;
            if (isset($data['module_entitlements']) && is_array($data['module_entitlements'])) {
                foreach ($data['module_entitlements'] as $entitlement) {
                    if (isset($entitlement['code']) && $entitlement['code'] === 'STOCK') {
                        $stockEntitlement = $entitlement;
                        break;
                    }
                }
            }

            if (!$stockEntitlement || $stockEntitlement['status'] !== 'ACTIVE') {
                return response()->json([
                    'success' => false,
                    'message' => 'Le certificat ne contient pas de licence active pour le module Gestion de Stock (STOCK).'
                ], 403);
            }

            $stockExpiresAt = isset($stockEntitlement['expires_at']) ? Carbon::parse($stockEntitlement['expires_at']) : null;
            if (!$stockExpiresAt || $stockExpiresAt->isPast()) {
                return response()->json([
                    'success' => false,
                    'message' => 'La licence du module Stock a expiré.'
                ], 403);
            }

            $user = $request->user();
            $licenseKey = $data['license']['license_key'] ?? null;

            if (!$licenseKey) {
                return response()->json([
                    'success' => false,
                    'message' => 'Clé de licence introuvable dans le certificat.'
                ], 422);
            }

            License::updateOrCreate(
                ['license_key' => $licenseKey],
                [
                    'user_id' => $user ? $user->id : null,
                    'format' => $data['format'],
                    'issuer' => $data['issuer'] ?? 'SGL',
                    'client_external_id' => $data['client']['id'] ?? null,
                    'client_name' => $data['client']['name'] ?? null,
                    'client_email' => $data['client']['email'] ?? null,
                    'app_id' => $data['application']['id'] ?? null,
                    'app_code' => $data['application']['code'] ?? null,
                    'app_name' => $data['application']['name'] ?? null,
                    'license_id' => $data['license']['id'] ?? null,
                    'reference' => $data['license']['reference'] ?? null,
                    'status' => $data['license']['status'] ?? 'ACTIVE',
                    'jti' => $data['license']['jti'] ?? null,
                    'kid' => $data['license']['kid'] ?? null,
                    'modules' => ['STOCK'],
                    'module_entitlements' => [$stockEntitlement],
                    'issued_at' => isset($stockEntitlement['issued_at']) ? Carbon::parse($stockEntitlement['issued_at']) : null,
                    'expires_at' => $stockExpiresAt,
                    'generated_at' => isset($data['generated_at']) ? Carbon::parse($data['generated_at']) : null,
                    'signature_alg' => $data['signature']['alg'] ?? null,
                    'signature_kid' => $data['signature']['kid'] ?? null,
                    'signature_encoding' => $data['signature']['encoding'] ?? null,
                    'signature_value' => $data['signature']['value'] ?? null,
                    'raw_certificate' => $fileContent,
                ]
            );

            return response()->json([
                'success' => true,
                'message' => 'Certificat importé avec succès. Le module Stock est activé !',
                'modules' => ['STOCK'],
                'expires_at' => $stockExpiresAt->format('d/m/Y H:i')
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du traitement du fichier de certificat.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
