<?php

namespace App\Http\Controllers;

use App\Models\OtherCashIn;
use App\Models\PaymentRegulation;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
/**
 * @permission_category Gestion des autres encaissements
 * @permission_module Gestion du restaurant
 */
class OtherCashInController extends Controller
{
    /**
     * Display a listing of the resource.
     * @permission OtherCashInController::index
     * @permission_desc Afficher la liste des autres encaissements
     */
    public function index(Request $request)
    {
        $auth = auth()->user();
        $perPage = $request->input('limit', 25);
        $page = $request->input('page', 1);

        $query = OtherCashIn::with([
            'regulationMethod',
            'creator',
            'updater',
            'validator',
            'canceller',
            'medias'
        ]);

        $query->when($request->filled('date'), function ($q) use ($request) {
            $q->whereDate('created_at', $request->date);
        }, function ($q) {
            $q->whereDate('created_at', today());
        });

        $query->when(trim($request->input('search')), function ($q, $search) {
            $q->where(function ($subQ) use ($search) {
                $subQ->where('uuid', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        });

        $data = $query->latest()->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data'         => $data->items(),
            'current_page' => $data->currentPage(),
            'last_page'    => $data->lastPage(),
            'total'        => $data->total(),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     * @permission OtherCashInController::store
     * @permission_desc Créer un autre encaissement
     */
    public function store(Request $request)
    {
        if ($request->has('family_hierarchy_uuids') && is_string($request->family_hierarchy_uuids)) {
            $request->merge([
                'family_hierarchy_uuids' => json_decode($request->family_hierarchy_uuids, true)
            ]);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0',
            'regulation_method_uuid' => 'required|uuid|exists:regulation_methods,uuid',
            'cash_receipt_family_uuid' => 'nullable|uuid|exists:cash_receipt_families,uuid',
            'family_hierarchy_uuids' => 'nullable|array',
            'date' => 'nullable|date',
            'attachment' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:2048',
        ]);

        return DB::transaction(function () use ($request, $validated) {
            $recordDate = isset($validated['date']) ? $validated['date'] . ' ' . now()->format('H:i:s') : now();

            $otherCashIn = OtherCashIn::create([
                'name' => $validated['name'],
                'amount' => $validated['amount'],
                'regulation_method_uuid' => $validated['regulation_method_uuid'],
                'cash_receipt_family_uuid' => $validated['cash_receipt_family_uuid'] ?? null,
                'family_hierarchy_uuids' => $validated['family_hierarchy_uuids'] ?? null,
                'status' => 'validated',
                'slug' => 'AUTRES ENCAISSEMENTS',
                'created_by' => auth()->id(),
                'validated_by' => auth()->id(),
                'created_at' => $recordDate,
                'updated_at' => $recordDate,
            ]);

            if ($request->hasFile('attachment')) {
                $file = $request->file('attachment');
                $filename = time() . '_' . $file->getClientOriginalName();
                $path = $file->store('other_cash_ins', 'public');

                $otherCashIn->medias()->create([
                    'name' => $filename,
                    'disk' => 'public',
                    'path' => $path,
                    'filename' => $filename,
                    'mimetype' => $file->getClientMimeType(),
                    'extension' => $file->getClientOriginalExtension(),
                ]);
                $otherCashIn->update(['attachment' => $path]);
            }

            $paymentRegulation = PaymentRegulation::create([
                'uuid' => (string) Str::uuid(),
                'other_cash_ins_uuid' => $otherCashIn->uuid,
                'regulation_method_uuid' => $validated['regulation_method_uuid'],
                'amount' => $validated['amount'],
                'type' => 'encaissement',
                'slug' => 'AUTRES ENCAISSEMENTS',
                'created_by' => auth()->id(),
                'created_at' => $recordDate,
                'updated_at' => $recordDate,
            ]);

            if ($request->hasFile('attachment')) {
                $file = $request->file('attachment');
                $filename = time() . '_' . $file->getClientOriginalName();
                $path = $file->store('other_cash_ins_payments', 'public');

                $paymentRegulation->medias()->create([
                    'name' => $filename,
                    'disk' => 'public',
                    'path' => $path,
                    'filename' => $filename,
                    'mimetype' => $file->getClientMimeType(),
                    'extension' => $file->getClientOriginalExtension(),
                ]);
                $paymentRegulation->update(['attachment' => $path]);
            }

            return response()->json([
                'message' => 'Encaissement enregistré avec succès.',
                'data' => $otherCashIn
            ], 201);
        });
    }

    /**
     * Store a newly created resource in storage.
     * @permission OtherCashInController::update_other_cash_ins
     * @permission_desc Modifier un autre encaissement
     */
    public function update_other_cash_ins(Request $request, $uuid)
    {
        $otherCashIn = OtherCashIn::where('uuid', $uuid)->firstOrFail();

        if ($request->has('family_hierarchy_uuids') && is_string($request->family_hierarchy_uuids)) {
            $request->merge([
                'family_hierarchy_uuids' => json_decode($request->family_hierarchy_uuids, true)
            ]);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0',
            'regulation_method_uuid' => 'required|uuid|exists:regulation_methods,uuid',
            'cash_receipt_family_uuid' => 'nullable|uuid|exists:cash_receipt_families,uuid',
            'family_hierarchy_uuids' => 'nullable|array',
            'date' => 'nullable|date',
            'attachment' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:2048',
        ]);

        return DB::transaction(function () use ($request, $validated, $otherCashIn) {
            $recordDate = isset($validated['date']) ? $validated['date'] . ' ' . now()->format('H:i:s') : $otherCashIn->created_at;

            $otherCashIn->update([
                'name' => $validated['name'],
                'amount' => $validated['amount'],
                'regulation_method_uuid' => $validated['regulation_method_uuid'],
                'cash_receipt_family_uuid' => $validated['cash_receipt_family_uuid'] ?? null,
                'family_hierarchy_uuids' => $validated['family_hierarchy_uuids'] ?? null,
                'updated_by' => auth()->id(),
                'updated_at' => $recordDate,
            ]);

            if ($request->hasFile('attachment')) {
                $file = $request->file('attachment');
                $filename = time() . '_' . $file->getClientOriginalName();
                $path = $file->store('other_cash_ins', 'public');

                $otherCashIn->medias()->create([
                    'name' => $filename,
                    'disk' => 'public',
                    'path' => $path,
                    'filename' => $filename,
                    'mimetype' => $file->getClientMimeType(),
                    'extension' => $file->getClientOriginalExtension(),
                ]);
                $otherCashIn->update(['attachment' => $path]);
            }

            $paymentRegulation = PaymentRegulation::where('other_cash_ins_uuid', $otherCashIn->uuid)->first();

            if ($paymentRegulation) {
                $paymentRegulation->update([
                    'regulation_method_uuid' => $validated['regulation_method_uuid'],
                    'amount' => $validated['amount'],
                    'updated_by' => auth()->id(),
                    'updated_at' => $recordDate,
                ]);

                if ($request->hasFile('attachment')) {
                    $file = $request->file('attachment');
                    $filename = time() . '_' . $file->getClientOriginalName();
                    $path = $file->store('other_cash_ins_payments', 'public');

                    $paymentRegulation->medias()->create([
                        'name' => $filename,
                        'disk' => 'public',
                        'path' => $path,
                        'filename' => $filename,
                        'mimetype' => $file->getClientMimeType(),
                        'extension' => $file->getClientOriginalExtension(),
                    ]);
                    $paymentRegulation->update(['attachment' => $path]);
                }
            }

            return response()->json([
                'message' => 'Encaissement mis à jour avec succès.',
                'data' => $otherCashIn
            ], 200);
        });
    }


    /**
     * Store a newly created resource in storage.
     * @permission OtherCashInController::cancel
     * @permission_desc Annuler un autre encaissement
     */
    public function cancel(Request $request, $uuid)
    {
        $validated = $request->validate([
            'reason_of_cancelled' => 'required|string|max:255',
        ]);

        return DB::transaction(function () use ($request, $validated, $uuid) {

            $otherCashIn = OtherCashIn::findOrFail($uuid);
            $paymentRegulation = PaymentRegulation::where('other_cash_ins_uuid', $otherCashIn->uuid)->first();

            $otherCashIn->update([
                'status' => 'cancelled',
                'cancelled_by' => auth()->id(),
                'reason_of_cancelled' => $validated['reason_of_cancelled'],
            ]);

            if ($paymentRegulation) {
                $paymentRegulation->update([
                    'status' => 'cancelled',
                    'reason_for_cancel_or_update' => $validated['reason_of_cancelled'],
                ]);
                $paymentRegulation->delete();
            }

            return response()->json([
                'message' => 'Encaissement annulé avec succès.',
                'data' => $otherCashIn
            ], 200);
        });
    }


    /**
     * Display a listing of the resource.
     * @permission OtherCashInController::cancelGroup
     * @permission_desc Annuler le groupe d'un autre encaissement
     */
    public function cancelGroup(Request $request)
    {
        $auth = auth()->user();

        $request->validate([
            'group_id'            => 'nullable|string',
            'reason_of_cancelled' => 'required|string|max:255',
        ]);

        $query = OtherCashIn::where('status', '!=', 'cancelled');

        if ($request->filled('group_id')) {
            $query->where('cash_receipt_family_uuid', $request->group_id);
        }

        $otherCashIns = $query->get();

        if ($otherCashIns->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Aucun encaissement actif trouvé.'
            ], 422);
        }

        DB::beginTransaction();

        try {
            $cashInUuids = $otherCashIns->pluck('uuid')->toArray();

            OtherCashIn::whereIn('uuid', $cashInUuids)->update([
                'status'              => 'cancelled',
                'cancelled_by'        => $auth->id,
                'reason_of_cancelled' => $request->reason_of_cancelled,
            ]);

            PaymentRegulation::whereIn('other_cash_ins_uuid', $cashInUuids)->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Tous les encaissements ciblés ont été annulés avec succès.',
                'count'   => count($cashInUuids)
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => "Une erreur est survenue lors de l'annulation.",
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display a listing of the resource.
     * @permission OtherCashInController::cancelFamily
     * @permission_desc Annuler la famille ou sous-famille d'un autre encaissement
     */
    public function cancelFamily(Request $request)
    {
        $auth = auth()->user();

        $request->validate([
            'family_id'           => 'required|string',
            'reason_of_cancelled' => 'required|string|max:255',
        ]);

        $familyUuid = $request->family_id;

        $otherCashIns = OtherCashIn::where('status', '!=', 'cancelled')
            ->where(function ($q) use ($familyUuid) {
                $q->where('cash_receipt_family_uuid', $familyUuid)
                    ->orWhereJsonContains('family_hierarchy_uuids', $familyUuid);
            })
            ->get();

        if ($otherCashIns->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Aucun encaissement actif trouvé pour cette famille.'
            ], 422);
        }

        DB::beginTransaction();

        try {
            $cashInUuids = $otherCashIns->pluck('uuid')->toArray();

            OtherCashIn::whereIn('uuid', $cashInUuids)->update([
                'status'              => 'cancelled',
                'cancelled_by'        => $auth->id,
                'reason_of_cancelled' => $request->reason_of_cancelled,
            ]);

            PaymentRegulation::whereIn('other_cash_ins_uuid', $cashInUuids)->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Tous les encaissements de la famille ont été annulés avec succès.',
                'count'   => count($cashInUuids)
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => "Une erreur est survenue lors de l'annulation de la famille.",
                'error'   => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     * @permission OtherCashInController::show
     * @permission_desc Afficher les détails d'un autre encaissement
     */
    public function show($uuid)
    {
        $otherCashIn = OtherCashIn::with([
            'regulationMethod',
            'creator',
            'updater',
            'validator',
            'canceller',
            'medias'
        ])->findOrFail($uuid);

        return response()->json([
            'message' => 'Détails de l\'encaissement récupérés avec succès.',
            'data' => $otherCashIn
        ], 200);
    }
}
