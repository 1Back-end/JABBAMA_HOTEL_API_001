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
                'modules' => $license->active_modules,
                'modules_details' => $license->modules_status_details,
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

            $entitlements = $data['module_entitlements'] ?? [];

            if (!is_array($entitlements) || empty($entitlements)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Le certificat ne contient aucun module ou droit d\'accès.'
                ], 403);
            }

            $activeModules = [];
            $latestExpiration = null;

            foreach ($entitlements as $entitlement) {
                $code = $entitlement['code'] ?? null;
                $status = $entitlement['status'] ?? null;
                $expiresAt = isset($entitlement['expires_at']) ? Carbon::parse($entitlement['expires_at']) : null;

                if ($code && $status === 'ACTIVE' && $expiresAt && !$expiresAt->isPast()) {
                    $activeModules[] = $code;

                    if (!$latestExpiration || $expiresAt->greaterThan($latestExpiration)) {
                        $latestExpiration = $expiresAt;
                    }
                }
            }

            if (empty($activeModules)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucune licence active ou valide n\'a été trouvée dans le certificat.'
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
                    'format' => $data['format'] ?? 'unknown',
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
                    'modules' => $activeModules,
                    'module_entitlements' => $entitlements,
                    'issued_at' => isset($data['license']['issued_at']) ? Carbon::parse($data['license']['issued_at']) : null,
                    'expires_at' => $latestExpiration,
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
                'message' => 'Certificat importé avec succès. Tous les modules valides sont activés !',
                'modules' => $activeModules,
                'expires_at' => $latestExpiration ? $latestExpiration->format('d/m/Y H:i') : null
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
