<?php

namespace App\Http\Controllers;

use App\Models\OtherCashIn;
use App\Models\PaymentRegulation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0',
            'regulation_method_uuid' => 'required|uuid|exists:regulation_methods,uuid',
            'date' => 'nullable|date',
            'attachment' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:2048',
        ]);

        return DB::transaction(function () use ($request, $validated) {
            $recordDate = isset($validated['date']) ? $validated['date'] . ' ' . now()->format('H:i:s') : now();

            $otherCashIn = OtherCashIn::create([
                'name' => $validated['name'],
                'amount' => $validated['amount'],
                'regulation_method_uuid' => $validated['regulation_method_uuid'],
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
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0',
            'regulation_method_uuid' => 'required|uuid|exists:regulation_methods,uuid',
            'date' => 'nullable|date',
            'attachment' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:2048',
        ]);

        return DB::transaction(function () use ($request, $validated, $uuid) {

            $otherCashIn = OtherCashIn::findOrFail($uuid);
            $paymentRegulation = PaymentRegulation::where('other_cash_ins_uuid', $otherCashIn->uuid)->first();

            $recordDate = isset($validated['date']) ? $validated['date'] . ' ' . now()->format('H:i:s') : $otherCashIn->created_at;

            $otherCashIn->update([
                'name' => $validated['name'],
                'amount' => $validated['amount'],
                'regulation_method_uuid' => $validated['regulation_method_uuid'],
                'updated_by' => auth()->id(),
                'validated_by' => auth()->id(),
                'created_at' => $recordDate,
                'updated_at' => $recordDate,
            ]);

            if ($request->hasFile('attachment')) {
                foreach ($otherCashIn->medias as $media) {
                    \Storage::disk($media->disk)->delete($media->path);
                    $media->delete();
                }

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

            if ($paymentRegulation) {
                $paymentRegulation->update([
                    'regulation_method_uuid' => $validated['regulation_method_uuid'],
                    'amount' => $validated['amount'],
                    'updated_by' => auth()->id(),
                    'created_at' => $recordDate,
                    'updated_at' => $recordDate,
                ]);

                if ($request->hasFile('attachment')) {
                    foreach ($paymentRegulation->medias as $media) {
                        \Storage::disk($media->disk)->delete($media->path);
                        $media->delete();
                    }

                    $pathPayment = $file->store('other_cash_ins_payments', 'public');

                    $paymentRegulation->medias()->create([
                        'name' => $filename,
                        'disk' => 'public',
                        'path' => $pathPayment,
                        'filename' => $filename,
                        'mimetype' => $file->getClientMimeType(),
                        'extension' => $file->getClientOriginalExtension(),
                    ]);
                    $paymentRegulation->update(['attachment' => $pathPayment]);
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
