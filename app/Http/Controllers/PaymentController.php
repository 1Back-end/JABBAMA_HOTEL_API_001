<?php

namespace App\Http\Controllers;

use App\Enums\CashRegisterFilterType;
use App\Enums\MenuOrderStatus;
use App\Enums\OrderMenuRestaurantItemStatus;
use App\Enums\PaymentOrderItemStatus;
use App\Enums\PaymentOrderMenusStatus;
use App\Enums\PaymentStatus;
use App\Models\CashReceiptFamily;
use App\Models\CashReceiptType;
use App\Models\ExpensePayment;
use App\Models\OrderMenuRestaurant;
use App\Models\OrderMenuRestaurantItem;
use App\Models\OrderRestaurantDrink;
use App\Models\Payment;
use App\Models\PaymentLine;
use App\Models\PaymentRegulation;
use App\Models\Recouvrement;
use App\Models\RegulationMethod;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{

    private function refreshPaymentStatus(OrderMenuRestaurant $order): void
    {
        $order->load(['items', 'drinks']);

        $allLines = $order->items->merge($order->drinks);

        if ($allLines->isEmpty()) {
            $order->status = PaymentOrderMenusStatus::NOT_PAID->value;
            $order->save();
            return;
        }

        $total = $allLines->count();

        $paidCount    = $allLines->where('regulation_status', PaymentOrderItemStatus::PAID->value)->count();
        $partialCount = $allLines->where('regulation_status', PaymentOrderItemStatus::PARTIALLY_PAID->value)->count();
        $notPaidCount = $allLines->where('regulation_status', PaymentOrderItemStatus::NOT_PAID->value)->count();

        if ($paidCount === $total) {
            $order->regulation_status = PaymentOrderMenusStatus::PAID->value;
        }
        elseif ($notPaidCount === $total) {
            $order->regulation_status = PaymentOrderMenusStatus::NOT_PAID->value;
        }
        else {
            $order->regulation_status = PaymentOrderMenusStatus::PARTIALLY_PAID->value;
        }
        $order->save();
    }

    private function refreshLinesPaymentStatus(OrderMenuRestaurant $order): void
    {
        $order->load([
            'items.paymentLines',
            'drinks.paymentLines'
        ]);

        $lines = $order->items->merge($order->drinks);

        foreach ($lines as $line) {
            $total = (float) $line->unit_price * (float) $line->quantity_exactly;
            if ($total === 0.0) {
                $line->regulation_status = PaymentOrderItemStatus::PAID->value;
                $line->save();
                continue;
            }
            $paidAmount = (float) $line->paymentLines->sum('amount');

            if ($paidAmount <= 0) {
                $line->regulation_status = PaymentOrderMenusStatus::NOT_PAID->value;
            }
            elseif ($paidAmount < $total) {
                $line->regulation_status = PaymentOrderMenusStatus::PARTIALLY_PAID->value;
            }
            else {
                $line->regulation_status = PaymentOrderMenusStatus::PAID->value;
            }

            $line->save();
        }
    }

    public function store(Request $request)
    {
        $auth = auth()->user();

        $request->validate([
            'order_menu_restaurant_uuid' => 'required|uuid',
            'total_amount' => 'required|numeric|min:0',
            'date' => 'nullable|date',
            'regulations' => 'required|array|min:1',
            'regulations.*.method_uuid' => 'required|uuid',
            'regulations.*.amount' => 'required|numeric|min:0.01',
            'regulations.*.lines' => 'nullable|array',
        ]);

        $createdAt = $request->filled('date')
            ? Carbon::parse($request->date)->setTimeFrom(Carbon::now())
            : Carbon::now();

        DB::beginTransaction();

        try {

            $order = OrderMenuRestaurant::with([
                'items.paymentLines',
                'drinks.paymentLines',
                'free_client_for_restaurant',
                'partners_restaurant'
            ])->where('uuid', $request->order_menu_restaurant_uuid)->firstOrFail();

            $payment = Payment::firstOrCreate(
                ['order_menu_restaurant_uuid' => $order->uuid],
                [
                    'paid_amount' => 0,
                    'remaining_amount' => (float) $order->total_order,
                    'status' => PaymentStatus::UNPAID->value,
                    'created_by' => auth()->id(),
                    'created_at' => $createdAt,
                ]
            );

            $payment->total_amount = (float) $order->total_order;
            $payment->save();

            $alreadyPaid = PaymentRegulation::where('payment_uuid', $payment->uuid)->whereNull('deleted_at')->sum('amount');

            $totalNewPaid = 0;
            $errors = [];

            $client = $order->free_client_for_restaurant
                ?? $order->partners_restaurant;

            $isClientAdvance = false;
            if ($client && isset($client->amount_allocated)) {
                $availableAdvance = (float) $client->amount_allocated;
                $isClientAdvance = true;
            } else {
                $availableAdvance = (float) ($order->amount_allocated ?? 0);
            }

            if ($availableAdvance <= 0) {
                return response()->json([
                    'success' => false,
                    'message' => "Montant des arrhes insuffisant. Veuillez recharger le solde.",
                ], 422);
            }


            foreach ($request->regulations as $index => $regulation) {

                $method = RegulationMethod::where('uuid', $regulation['method_uuid'])->first();

                if (!$method) {
                    $errors["regulations.$index.method_uuid"][] = "Méthode de règlement invalide";
                    continue;
                }

                if ($method->comment_required && empty($regulation['reference'])) {
                    $errors["regulations.$index.reference"][] = "La référence est obligatoire";
                }

                if ($method->phone_method && empty($regulation['phone_number'])) {
                    $errors["regulations.$index.phone_number"][] = "Le numéro est obligatoire";
                }

                if ($method->comment_required && empty($regulation['detail'])) {
                    $errors["regulations.$index.detail"][] = "Le commentaire est obligatoire";
                }

                if (!isset($regulation['amount']) || !is_numeric($regulation['amount']) || $regulation['amount'] <= 0) {
                    $errors["regulations.$index.amount"][] = "Montant invalide";
                }

                $totalNewPaid += (float) $regulation['amount'];
            }

            if (!empty($errors)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Erreurs de validation',
                    'errors' => $errors
                ], 422);
            }

            if ($totalNewPaid > $availableAdvance) {
                return response()->json([
                    'success' => false,
                    'message' => "Montant des arrhes insuffisant. Disponible: {$availableAdvance}, demandé: {$totalNewPaid}. Veuillez recharger le solde.",
                ], 422);
            }

            $remainingToPay = max(0, (float) $payment->total_amount - $alreadyPaid);

            \Log::info('PAYMENT DEBUG', [
                'payment_total_amount' => $payment->total_amount,
                'already_paid' => $alreadyPaid,
                'remaining' => $remainingToPay,
                'request_total_amount' => $request->total_amount,
            ]);

            if ($totalNewPaid > ($remainingToPay + 0.01)) {
                return response()->json([
                    'success' => false,
                    'message' => "Montant supérieur au reste à payer ({$remainingToPay})",
                ], 422);
            }


            foreach ($request->regulations as $regulation) {

                $method = RegulationMethod::where('uuid', $regulation['method_uuid'])->first();
                $cashReceiptType = CashReceiptType::where('is_linked_to_turnover', true)->first();

                // 1. Regrouper et sommer les montants par type (item = resto, drink = bar)
                $itemsAmount = 0;
                $drinksAmount = 0;
                $itemLines = [];
                $drinkLines = [];

                if (!empty($regulation['lines'])) {
                    foreach ($regulation['lines'] as $line) {
                        if ($line['type'] === 'item') {
                            $itemsAmount += (float) $line['amount'];
                            $itemLines[] = $line;
                        } elseif ($line['type'] === 'drink') {
                            $drinksAmount += (float) $line['amount'];
                            $drinkLines[] = $line;
                        }
                    }
                } else {
                    // S'il n'y a pas de lignes détaillées, on attribue tout au montant global (par défaut Resto ou autre selon votre logique)
                    $itemsAmount = (float) $regulation['amount'];
                }

                // 2. Traitement de la partie RESTO (si présente)
                if ($itemsAmount > 0) {
                    $restoFamily = CashReceiptFamily::where('indexation', 'Consommation Restaurant')->first();

                    $regulationModelResto = PaymentRegulation::create([
                        'payment_uuid' => $payment->uuid,
                        'regulation_method_uuid' => $method->uuid,
                        'cash_receipt_families_uuid' => $restoFamily?->uuid,
                        'cash_receipt_type_uuid' => $cashReceiptType?->uuid,
                        'slug' => 'ENCAISSEMENT RESTO',
                        'amount' => $itemsAmount,
                        'phone_number' => $regulation['phone_number'] ?? null,
                        'reference' => $regulation['reference'] ?? null,
                        'detail' => $regulation['detail'] ?? null,
                        'created_by' => auth()->id(),
                        'updated_by' => auth()->id(),
                        'created_at' => $createdAt,
                        'updated_at' => $createdAt,
                    ]);

                    // Enregistrer les payment_lines liées au resto
                    foreach ($itemLines as $line) {
                        PaymentLine::create([
                            'payment_uuid' => $payment->uuid,
                            'payment_regulation_uuid' => $regulationModelResto->uuid,
                            'payable_type' => get_class($order->items()->getModel()),
                            'payable_uuid' => $line['uuid'],
                            'amount' => $line['amount'],
                            'slug' => 'RESTO',
                            'regulation_method_uuid' => $method->uuid,
                            'phone_number' => $regulation['phone_number'] ?? null,
                            'reference' => $regulation['reference'] ?? null,
                            'detail' => $regulation['detail'] ?? null,
                            'created_by' => auth()->id(),
                            'updated_by' => auth()->id(),
                            'created_at' => $createdAt,
                            'updated_at' => $createdAt,
                        ]);
                    }
                }

                // 3. Traitement de la partie BAR (si présente)
                if ($drinksAmount > 0) {
                    $barFamily = CashReceiptFamily::where('indexation', 'Consommation Bar')->first();

                    $regulationModelBar = PaymentRegulation::create([
                        'payment_uuid' => $payment->uuid,
                        'regulation_method_uuid' => $method->uuid,
                        'cash_receipt_families_uuid' => $barFamily?->uuid,
                        'cash_receipt_type_uuid' => $cashReceiptType?->uuid,
                        'slug' => 'ENCAISSEMENT BAR',
                        'amount' => $drinksAmount,
                        'phone_number' => $regulation['phone_number'] ?? null,
                        'reference' => $regulation['reference'] ?? null,
                        'detail' => $regulation['detail'] ?? null,
                        'created_by' => auth()->id(),
                        'updated_by' => auth()->id(),
                        'created_at' => $createdAt,
                        'updated_at' => $createdAt,
                    ]);

                    // Enregistrer les payment_lines liées au bar
                    foreach ($drinkLines as $line) {
                        PaymentLine::create([
                            'payment_uuid' => $payment->uuid,
                            'payment_regulation_uuid' => $regulationModelBar->uuid,
                            'payable_type' => get_class($order->drinks()->getModel()),
                            'payable_uuid' => $line['uuid'],
                            'amount' => $line['amount'],
                            'slug' => 'BAR',
                            'regulation_method_uuid' => $method->uuid,
                            'phone_number' => $regulation['phone_number'] ?? null,
                            'reference' => $regulation['reference'] ?? null,
                            'detail' => $regulation['detail'] ?? null,
                            'created_by' => auth()->id(),
                            'updated_by' => auth()->id(),
                            'created_at' => $createdAt,
                            'updated_at' => $createdAt,
                        ]);
                    }
                }
            }


            $totalPaid = $alreadyPaid + $totalNewPaid;

            $payment->paid_amount = $totalPaid;
            $payment->remaining_amount = max(0, $payment->total_amount - $totalPaid);

            if ($totalPaid <= 0) {
                $payment->status = PaymentStatus::UNPAID->value;
            } elseif ($totalPaid < $payment->total_amount) {
                $payment->status = PaymentStatus::PARTIALLY_PAID->value;
            } else {
                $payment->status = PaymentStatus::PAID->value;
            }

            $payment->save();

            $order->updated_by = auth()->id();
            $order->save();


            $usedAdvance = min($availableAdvance, $totalNewPaid);

            if ($usedAdvance > 0) {
                if ($client) {
                    $client->decrement('amount_allocated', $usedAdvance);
                } else {
                    $order->decrement('amount_allocated', $usedAdvance);
                }
            }

            $order->refresh();

            $this->refreshLinesPaymentStatus($order);

            $order->refresh();

            $this->refreshPaymentStatus($order);

            $order->update([
                'updated_by' => $auth->id,
            ]);


            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Paiement enregistré avec succès',
                'data' => $payment->load('order')
            ]);

        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }



    public function cancel(Request $request, $uuid)
    {
        $auth = auth()->user();

        $request->validate([
            'type' => 'required|in:item,drink,order,regulation',
            'password' => 'required|string'
        ]);

        if (!Hash::check($request->password, $auth->password)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Mot de passe incorrect.'
            ], 422);
        }

        DB::beginTransaction();

        try {

            $payment = Payment::with(['order'])->where('uuid', $uuid)->firstOrFail();
            $order = $payment->order;

            $totalAmountToRefund = 0;

            if ($request->type === 'item') {
                $lines = PaymentLine::where('payable_uuid', $request->target_uuid)
                    ->where('payable_type', OrderMenuRestaurantItem::class)
                    ->where('payment_uuid', $payment->uuid)
                    ->get();

                if ($lines->isEmpty()) {
                    throw new \Exception("Aucun règlement trouvé pour cet item");
                }

                $refund = $lines->sum('amount');

                foreach ($lines as $line) {
                    $regulation = PaymentRegulation::where('uuid', $line->payment_regulation_uuid)
                        ->first();

                    if ($regulation) {
                        $regulation->amount = max(0, (float)$regulation->amount - (float)$line->amount);
                        if (round($regulation->amount, 2) <= 0) {
                            $regulation->delete();
                        } else {
                            $regulation->save();
                            $regulation->updated_by = auth()->id();
                        }
                    }
                    $line->delete();
                }

                $payment->paid_amount = max(0, $payment->paid_amount - $refund);

                OrderMenuRestaurantItem::where('uuid', $request->target_uuid)
                    ->update([
                        'regulation_status' => PaymentOrderItemStatus::NOT_PAID->value
                    ]);

                $totalAmountToRefund += $refund;
            }


            if ($request->type === 'drink') {
                $lines = PaymentLine::where('payable_uuid', $request->target_uuid)
                    ->where('payable_type', OrderRestaurantDrink::class)
                    ->where('payment_uuid', $payment->uuid)
                    ->get();

                if ($lines->isEmpty()) {
                    throw new \Exception("Aucun règlement trouvé pour ce drink");
                }

                $refund = $lines->sum('amount');

                foreach ($lines as $line) {
                    $regulation = PaymentRegulation::where('uuid', $line->payment_regulation_uuid)
                        ->first();

                    if ($regulation) {
                        $regulation->amount = max(0, (float)$regulation->amount - (float)$line->amount);
                        if (round($regulation->amount, 2) <= 0) {
                            $regulation->delete();
                        } else {
                            $regulation->save();
                            $regulation->updated_by = auth()->id();
                        }
                    }
                    $line->delete();
                }

                $payment->paid_amount = max(0, $payment->paid_amount - $refund);

                OrderRestaurantDrink::where('uuid', $request->target_uuid)
                    ->update([
                        'regulation_status' => PaymentOrderItemStatus::NOT_PAID->value
                    ]);

                $totalAmountToRefund += $refund;
            }


            if ($request->type === 'order') {
                $totalAmountToRefund = $payment->paid_amount;

                $paidItems = $order->items()
                    ->where('total_price', '>', 0)
                    ->whereIn('regulation_status', [
                        PaymentOrderItemStatus::PAID->value,
                        PaymentOrderItemStatus::PARTIALLY_PAID->value
                    ])
                    ->pluck('uuid');

                $paidDrinks = $order->drinks()
                    ->where('total_price', '>', 0)
                    ->whereIn('regulation_status', [
                        PaymentOrderItemStatus::PAID->value,
                        PaymentOrderItemStatus::PARTIALLY_PAID->value
                    ])
                    ->pluck('uuid');

                PaymentLine::where('payment_uuid', $payment->uuid)->delete();
                PaymentRegulation::where('payment_uuid', $payment->uuid)->delete();

                $order->items()->whereIn('uuid', $paidItems)->update([
                    'regulation_status' => PaymentOrderItemStatus::NOT_PAID->value
                ]);
                $order->drinks()->whereIn('uuid', $paidDrinks)->update([
                    'regulation_status' => PaymentOrderItemStatus::NOT_PAID->value
                ]);
                $hasOtherPaidItems = $order->items()
                    ->whereIn('regulation_status', [PaymentOrderItemStatus::PAID->value, PaymentOrderItemStatus::PARTIALLY_PAID->value])
                    ->exists();

                $hasOtherPaidDrinks = $order->drinks()
                    ->whereIn('regulation_status', [PaymentOrderItemStatus::PAID->value, PaymentOrderItemStatus::PARTIALLY_PAID->value])
                    ->exists();

                $order->regulation_status = ($hasOtherPaidItems || $hasOtherPaidDrinks)
                    ? PaymentOrderMenusStatus::PARTIALLY_PAID->value
                    : PaymentOrderMenusStatus::NOT_PAID->value;
                $order->updated_by = auth()->id();
                $order->save();

                $client = $order->free_client_for_restaurant ?? $order->partners_restaurant;
                if ($client && $totalAmountToRefund > 0) {
                    $client->increment('amount_allocated', $totalAmountToRefund);
                } else {
                    $order->increment('amount_allocated', $totalAmountToRefund);
                }

                $payment->delete();
                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'Annulation complète effectuée'
                ]);
            }


            $payment->paid_amount = max(0, $payment->paid_amount);
            $payment->remaining_amount = max(0, $payment->total_amount - $payment->paid_amount);

            if ($payment->paid_amount <= 0) {
                $payment->status = PaymentStatus::UNPAID->value;
            } elseif ($payment->paid_amount < $payment->total_amount) {
                $payment->status = PaymentStatus::PARTIALLY_PAID->value;
            } else {
                $payment->status = PaymentStatus::PAID->value;
            }
            $payment->save();


            $order->refresh();
            $this->refreshLinesPaymentStatus($order);
            $order->refresh();
            $this->refreshPaymentStatus($order);

            if ($totalAmountToRefund > 0) {
                $client = $order->free_client_for_restaurant ?? $order->partners_restaurant;
                if ($client) {
                    $client->increment('amount_allocated', $totalAmountToRefund);
                } else {
                    $order->increment('amount_allocated', $totalAmountToRefund);
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Annulation effectuée avec succès'
            ]);

        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }


    public function get_cash_register_sheet(Request $request)
    {
        $perPage = (int)$request->input('limit', 25);
        $page = (int)$request->input('page', 1);

        $date = $request->filled('date')
            ? Carbon::parse($request->date)->toDateString()
            : Carbon::today()->toDateString();

        $relations = ['creator:id,nom_utilisateur'];

        $selectColumns = [
            DB::raw('MIN(payment_regulations.uuid) as uuid'),
            DB::raw('DATE(payment_regulations.created_at) as date'),
            DB::raw('MIN(payment_regulations.created_at) as created_at'),
        ];

        $groupByColumns = [
            DB::raw('DATE(payment_regulations.created_at)'),
        ];

        $query = PaymentRegulation::query();
        $totalsQuery = PaymentRegulation::whereDate('created_at', $date);

        if ($request->cash_register_filter_type === CashRegisterFilterType::PAYMENT_METHOD->value) {

            // --- 1. FILTRE PAR MODE DE RÈGLEMENT ---
            $selectColumns[] = 'payment_regulations.regulation_method_uuid';
            $selectColumns[] = DB::raw("SUM(CASE WHEN payment_regulations.type IN ('encaissement', 'recouvrement') THEN payment_regulations.amount ELSE 0 END) as total_encaissements");
            $selectColumns[] = DB::raw("SUM(CASE WHEN payment_regulations.type = 'expense' THEN payment_regulations.amount ELSE 0 END) as total_depenses");
            $selectColumns[] = DB::raw("SUM(CASE WHEN payment_regulations.type IN ('encaissement', 'recouvrement') THEN payment_regulations.amount ELSE -payment_regulations.amount END) as total_amount");

            $groupByColumns[] = 'payment_regulations.regulation_method_uuid';
            $relations[] = 'method:uuid,name';

            if ($request->filled('regulation_method_uuid')) {
                $query->where('payment_regulations.regulation_method_uuid', $request->regulation_method_uuid);
                $totalsQuery->where('regulation_method_uuid', $request->regulation_method_uuid);
            }

        } elseif ($request->cash_register_filter_type === CashRegisterFilterType::PAYMENT_TYPE->value) {

            // --- 2. FILTRE PAR TYPE DE PAIEMENT (BAR / RESTO / AUTRES regroupés) ---
            $selectColumns[] = DB::raw("
            CASE
                WHEN payment_regulations.slug LIKE '%BAR%' THEN 'BAR'
                WHEN payment_regulations.slug LIKE '%RESTO%' THEN 'RESTO'
                ELSE 'AUTRES'
            END as category_slug
        ");

            $selectColumns[] = DB::raw("
            CASE
                WHEN payment_regulations.slug LIKE '%BAR%' THEN 'BAR'
                WHEN payment_regulations.slug LIKE '%RESTO%' THEN 'RESTO'
                ELSE 'AUTRES'
            END as category_type_name
        ");

            $selectColumns[] = DB::raw("SUM(CASE WHEN payment_regulations.type IN ('encaissement', 'recouvrement') THEN payment_regulations.amount ELSE 0 END) as total_encaissements");
            $selectColumns[] = DB::raw("SUM(CASE WHEN payment_regulations.type = 'expense' THEN payment_regulations.amount ELSE 0 END) as total_depenses");
            $selectColumns[] = DB::raw("SUM(CASE WHEN payment_regulations.type IN ('encaissement', 'recouvrement') THEN payment_regulations.amount ELSE -payment_regulations.amount END) as total_amount");

            $groupByColumns[] = DB::raw("
            CASE
                WHEN payment_regulations.slug LIKE '%BAR%' THEN 'BAR'
                WHEN payment_regulations.slug LIKE '%RESTO%' THEN 'RESTO'
                ELSE 'AUTRES'
            END
        ");

            if ($request->filled('slug')) {
                $slugFilter = $request->slug;

                $query->where(function($q) use ($slugFilter) {
                    $q->where(DB::raw("
                    CASE
                        WHEN payment_regulations.slug LIKE '%BAR%' THEN 'BAR'
                        WHEN payment_regulations.slug LIKE '%RESTO%' THEN 'RESTO'
                        ELSE 'AUTRES'
                    END
                "), 'LIKE', '%' . $slugFilter . '%');
                });

                $totalsQuery->where(DB::raw("
                CASE
                    WHEN payment_regulations.slug LIKE '%BAR%' THEN 'BAR'
                    WHEN payment_regulations.slug LIKE '%RESTO%' THEN 'RESTO'
                    ELSE 'AUTRES'
                END
            "), 'LIKE', '%' . $slugFilter . '%');
            }

        } else {

            $selectColumns[] = 'payment_regulations.created_by';
            $selectColumns[] = DB::raw("SUM(CASE WHEN payment_regulations.type IN ('encaissement', 'recouvrement') THEN payment_regulations.amount ELSE 0 END) as total_encaissements");
            $selectColumns[] = DB::raw("SUM(CASE WHEN payment_regulations.type = 'expense' THEN payment_regulations.amount ELSE 0 END) as total_depenses");
            $selectColumns[] = DB::raw("SUM(CASE WHEN payment_regulations.type IN ('encaissement', 'recouvrement') THEN payment_regulations.amount ELSE -payment_regulations.amount END) as total_amount");

            $groupByColumns[] = 'payment_regulations.created_by';

            if ($request->cash_register_filter_type === CashRegisterFilterType::CASHIER_AGENT->value && $request->filled('created_by')) {
                $query->where('payment_regulations.created_by', $request->created_by);
                $totalsQuery->where('created_by', $request->created_by);
            }
        }

        $queryBuilder = $query->select($selectColumns)
            ->with($relations)
            ->whereDate('payment_regulations.created_at', $date)
            ->whereNull('payment_regulations.deleted_at')
            ->groupBy($groupByColumns);

        if ($request->cash_register_filter_type === CashRegisterFilterType::PAYMENT_TYPE->value) {
            $queryBuilder->havingRaw("SUM(CASE WHEN payment_regulations.type IN ('encaissement', 'recouvrement') THEN payment_regulations.amount ELSE 0 END) > 0
                      OR SUM(CASE WHEN payment_regulations.type = 'expense' THEN payment_regulations.amount ELSE 0 END) > 0");
        }

        $data = $queryBuilder->orderByDesc(DB::raw('DATE(payment_regulations.created_at)'))
            ->paginate($perPage, ['*'], 'page', $page);

        $totals = $totalsQuery->select('type', DB::raw('SUM(amount) as total'))
            ->whereNull('deleted_at')
            ->groupBy('type')
            ->get()
            ->keyBy('type');

        $totalEncaissements = (float) ($totals->get('encaissement')?->total ?? 0);
        $totalRecouvrements = (float) ($totals->get('recouvrement')?->total ?? 0);
        $totalDepenses      = (float) ($totals->get('expense')?->total ?? 0);

        $totalEntreesGlobal = $totalEncaissements + $totalRecouvrements;
        $soldeNet           = $totalEntreesGlobal - $totalDepenses;

        return response()->json([
            'success'             => true,
            'data'                => $data->items(),
            'current_page'        => $data->currentPage(),
            'last_page'           => $data->lastPage(),
            'per_page'            => $data->perPage(),
            'total'               => $data->total(),
            'total_encaissements' => $totalEntreesGlobal,
            'total_depenses'      => $totalDepenses,
            'solde_net'           => $soldeNet,
        ]);
    }



    public function show_payments_by_uuid(string $uuid)
    {
        $paymentRegulation = PaymentRegulation::with([
            'creator:id,nom_utilisateur',
            'updater:id,nom_utilisateur',
            'expenseDetails',
            'restaurantExpenseType',
            'cashReceiptType',
            'sourceType.family',
            'cashReceiptFamily',
            'method',
            'payment.regulations',
            'payment.order.items',
            'payment.order.drinks',
        ])
            ->findOrFail($uuid);

        return response()->json([
            'success' => true,
            'message' => 'Détails du flux de caisse récupérés avec succès',
            'data'    => $paymentRegulation
        ], 200);
    }

    private function buildExpenseTree($items)
    {
        $tree = [];

        foreach ($items as $item) {

            $current = &$tree;

            // Si la dépense n'a ni hiérarchie ni famille directe, on récupère le vrai nom de la catégorie parente/type
            if ($item->hierarchy_families->isEmpty() && !$item->family) {
                $typeName = optional($item->expenseType)->name ?? 'Dépenses directes';
                $typeUuid = optional($item->expenseType)->uuid ?? null;
                $defaultKey = 'direct_type_' . ($typeUuid ?? 'general');

                if (!isset($current[$defaultKey])) {
                    $current[$defaultKey] = [
                        'uuid'     => $typeUuid,
                        'name'     => $typeName, // 🔹 Utilise le nom exact ici
                        'amount'   => 0,
                        'children' => [],
                        'items'    => [],
                    ];
                }

                $current[$defaultKey]['amount'] += (float) $item->amount;
                $current[$defaultKey]['items'][] = [
                    'uuid'   => $item->uuid,
                    'name'   => $item->name,
                    'amount' => (float) $item->amount,
                    'method' => $item->method,
                ];

                continue;
            }

            // Trier la hiérarchie par niveau
            $hierarchy = $item->hierarchy_families
                ->sortBy('level')
                ->values();

            /**
             * Construire :
             * DEPENSES RESTO
             *    └── CHARGES VARIABLE RESTO
             */
            foreach ($hierarchy as $family) {

                $uuid = $family->uuid;

                if (!isset($current[$uuid])) {

                    $current[$uuid] = [
                        'uuid'     => $uuid,
                        'name'     => $family->name,
                        'amount'   => 0,
                        'children' => [],
                        'items'    => [],
                    ];
                }

                $current[$uuid]['amount'] += (float) $item->amount;

                $current = &$current[$uuid]['children'];
            }

            /**
             * Ajouter la famille finale
             * FACT VARIABLE RESTO
             */
            if ($item->family) {

                $family = $item->family;

                if (!isset($current[$family->uuid])) {

                    $current[$family->uuid] = [
                        'uuid'     => $family->uuid,
                        'name'     => $family->name,
                        'amount'   => 0,
                        'children' => [],
                        'items'    => [],
                    ];
                }

                $current[$family->uuid]['amount'] += (float) $item->amount;

                $current[$family->uuid]['items'][] = [
                    'uuid'   => $item->uuid,
                    'name'   => $item->name,
                    'amount' => (float) $item->amount,
                    'method' => $item->method,
                ];
            } else {
                // Cas où il y a une hiérarchie mais pas de famille finale
                $current_key = 'direct_' . $item->uuid;
                $current[$current_key] = [
                    'uuid'   => $item->uuid,
                    'name'   => $item->name,
                    'amount' => (float) $item->amount,
                    'children' => [],
                    'items'  => [
                        [
                            'uuid'   => $item->uuid,
                            'name'   => $item->name,
                            'amount' => (float) $item->amount,
                            'method' => $item->method,
                        ]
                    ],
                ];
            }

            unset($current);
        }

        return $this->normalizeTree($tree);
    }

    private function normalizeTree(array $tree): array
    {
        return collect($tree)
            ->map(function ($node) {

                $node['children'] = $this->normalizeTree($node['children']);

                return $node;

            })
            ->values()
            ->toArray();
    }


    public function show_global_cashflow_today(Request $request)
    {
        $date = $request->filled('date') ? Carbon::parse($request->date)->toDateString() : null;
        $filterType = $request->cash_register_filter_type;
        $createdBy = $request->filled('created_by') ? $request->created_by : null;

        $slug = $request->filled('slug') ? strtoupper(trim($request->slug)) : null;

        $creator = null;
        if ($createdBy) {
            $creator = \App\Models\User::select('id', 'nom_utilisateur')->find($createdBy);
        }

        $slug = $request->filled('slug') ? strtoupper(trim($request->slug)) : null;

        $creator = null;
        if ($createdBy) {
            $creator = \App\Models\User::select('id', 'nom_utilisateur')->find($createdBy);
        }

        $slugLabel = $slug ? strtoupper($slug) : 'GLOBAL';

        // --- CORRECTION DES TITRES SELON LE SLUG ---
        if (!$slug) {
            $expenseTitle = 'DEPENSES GLOBAL';
            $otherCashInTitle = 'AUTRES ENCAISSEMENTS';
        } elseif (in_array($slug, ['BAR', 'RESTO'])) {
            $expenseTitle = 'DEPENSES ' . $slugLabel;
            $otherCashInTitle = 'AUTRES ENCAISSEMENTS ' . $slugLabel;
        } elseif ($slug === 'AUTRES') {
            $expenseTitle = 'AUTRES DEPENSES';
            $otherCashInTitle = 'AUTRES ENCAISSEMENTS';
        } else {
            $expenseTitle = 'AUTRES DEPENSES';
            $otherCashInTitle = 'AUTRES ENCAISSEMENTS ' . $slugLabel;
        }

        $receiptTitle = 'ENCAISSEMENT ' . $slugLabel;
        $recouvrementTitle = 'RECOUVREMENTS ' . $slugLabel;

        $expenses = collect();
        $receipts = collect();
        $recouvrements = collect();
        $otherCashIns = collect();

        $shouldFetchExpenses = $filterType !== 'payment_type' || $request->filled('restaurant_expense_type_uuid') || $slug;

        $shouldFetchReceipts = $filterType !== 'expense_type' && (
                $filterType !== 'payment_type' || $request->filled('cash_receipt_type_uuid') || $slug
            );

        $shouldFetchRecouvrements = $filterType !== 'expense_type' && (
                $filterType !== 'payment_type' || $request->filled('recouvrement_uuid') || $slug
            );

        $shouldFetchOtherCashIns = $filterType !== 'expense_type';

        if ($shouldFetchExpenses) {
            $expensesQuery = ExpensePayment::with([
                'creator:id,nom_utilisateur',
                'updater:id,nom_utilisateur',
                'expenseType:uuid,name,slug',
                'family:uuid,name',
                'method:uuid,name',
            ])
                ->where('status', 'paid')
                ->whereDate('paid_at', $date)
                ->whereNull('deleted_at');

            if ($slug) {
                if ($slug === 'AUTRES') {
                    $expensesQuery->where(function ($q) {
                        $q->whereNull('slug')
                            ->orWhere('slug', '')
                            ->orWhere('slug', 'AUTRES DEPENSES');
                    });
                } else {
                    $expensesQuery->where('slug', $slug);
                }
            }

            if ($request->filled('restaurant_expense_type_uuid')) {
                $expensesQuery->where('restaurant_expense_type_uuid', $request->restaurant_expense_type_uuid);
            }

            if ($filterType === 'payment_method' && $request->filled('regulation_method_uuid')) {
                $expensesQuery->whereHas('method', function ($query) use ($request) {
                    $query->where('uuid', $request->regulation_method_uuid);
                });
            }

            if ($filterType === 'cashier_agent' && $createdBy) {
                $expensesQuery->where('created_by', $createdBy);
            }

            $expenses = $expensesQuery->orderByDesc('paid_at')
                ->get()
                ->groupBy('restaurant_expense_type_uuid')
                ->map(function ($items) use ($expenseTitle) {
                    return [
                        'expense_type' => $items->first()->expenseType,
                        'title'        => $expenseTitle,
                        'total_amount' => (float) $items->sum('amount'),
                        'families'     => $this->buildExpenseTree($items),
                    ];
                })
                ->values();
        }

        if ($shouldFetchReceipts) {
            $receiptsQuery = PaymentRegulation::with([
                'creator:id,nom_utilisateur',
                'updater:id,nom_utilisateur',
                'cashReceiptType:uuid,name,slug',
                'cashReceiptFamily:uuid,name',
                'method:uuid,name',
                'payment.order',
                'payment.order.items.menu:uuid,name',
                'payment.order.drinks.drinkConfig.product',
                'paymentLines' => function ($lineQuery) use ($slug) {
                    if ($slug) {
                        $lineQuery->where('slug', $slug);
                    }
                },
                'paymentLines.payable' => function ($morphTo) {
                    $morphTo->morphWith([
                        \App\Models\OrderMenuRestaurantItem::class => ['menu:uuid,name'],
                        \App\Models\OrderRestaurantDrink::class => ['drinkConfig.product:uuid,name']
                    ]);
                }
            ])
                ->where('type', 'encaissement')
                ->whereNotNull('cash_receipt_type_uuid')
                ->whereDate('created_at', $date)
                ->whereNull('deleted_at');

            if ($slug) {
                $receiptsQuery->where(function ($q) use ($slug) {
                    $q->where('slug', 'like', '% ' . $slug)
                        ->orWhereHas('paymentLines', function ($lineQ) use ($slug) {
                            $lineQ->where('slug', $slug);
                        });
                });
            }

            if ($request->filled('cash_receipt_type_uuid')) {
                $receiptsQuery->where('cash_receipt_type_uuid', $request->cash_receipt_type_uuid);
            }

            if ($filterType === 'payment_method' && $request->filled('regulation_method_uuid')) {
                $receiptsQuery->where('regulation_method_uuid', $request->regulation_method_uuid);
            }

            if ($filterType === 'cashier_agent' && $createdBy) {
                $receiptsQuery->where('created_by', $createdBy);
            }

            $receipts = $receiptsQuery->orderByDesc('created_at')
                ->get()
                ->map(function ($regulation) use ($slug) {
                    if ($slug) {
                        $filteredLines = $regulation->paymentLines->where('slug', $slug);
                        if ($filteredLines->isEmpty()) {
                            return null;
                        }
                        $regulation->setRelation('paymentLines', $filteredLines);
                        $regulation->amount = (float) $filteredLines->sum('amount');
                    }
                    return $regulation;
                })
                ->filter()
                ->groupBy('cash_receipt_type_uuid')
                ->map(function ($items) use ($receiptTitle, $slug) {
                    return [
                        'receipt_type' => $items->first()->cashReceiptType,
                        'title'        => $receiptTitle,
                        'total_amount' => (float) $items->sum('amount'),
                        'items'        => $this->formatRegulationItems($items, $slug),
                    ];
                })->values();
        }

        if ($shouldFetchRecouvrements) {
            $recouvrementsQuery = PaymentRegulation::with([
                'creator:id,nom_utilisateur',
                'updater:id,nom_utilisateur',
                'recouvrement:uuid,name,code,slug',
                'cashReceiptFamily:uuid,name',
                'method:uuid,name',

                'payment.order.items.menu:uuid,name',
                'payment.order.drinks.drinkConfig.product',
                'payment.order.partners_restaurant:uuid,full_name',
                'payment.order.free_client_for_restaurant:uuid,full_name',

                'paymentLines' => function ($lineQuery) use ($slug) {
                    if ($slug) {
                        $lineQuery->where('slug', $slug);
                    }
                },
                'paymentLines.payable' => function ($morphTo) {
                    $morphTo->morphWith([
                        \App\Models\OrderMenuRestaurantItem::class => ['menu:uuid,name'],
                        \App\Models\OrderRestaurantDrink::class => ['drinkConfig.product:uuid,name']
                    ]);
                }
            ])
                ->where('type', 'recouvrement')
                ->whereNotNull('recouvrement_uuid')
                ->whereDate('created_at', $date)
                ->whereNull('deleted_at');

            if ($slug) {
                $recouvrementsQuery->where(function ($q) use ($slug) {
                    $q->where('slug', 'like', '% ' . $slug)
                        ->orWhereHas('paymentLines', function ($lineQ) use ($slug) {
                            $lineQ->where('slug', $slug);
                        });
                });
            }

            if ($request->filled('recouvrement_uuid')) {
                $recouvrementsQuery->where('recouvrement_uuid', $request->recouvrement_uuid);
            }

            if ($filterType === 'payment_method' && $request->filled('regulation_method_uuid')) {
                $recouvrementsQuery->where('regulation_method_uuid', $request->regulation_method_uuid);
            }

            if ($filterType === 'cashier_agent' && $createdBy) {
                $recouvrementsQuery->where('created_by', $createdBy);
            }

            $recouvrements = $recouvrementsQuery->orderByDesc('created_at')
                ->get()
                ->map(function ($regulation) use ($slug) {
                    if ($slug) {
                        $filteredLines = $regulation->paymentLines->where('slug', $slug);
                        if ($filteredLines->isEmpty()) {
                            return null;
                        }
                        $regulation->setRelation('paymentLines', $filteredLines);
                        $regulation->amount = (float) $filteredLines->sum('amount');
                    }

                    $order = optional($regulation->payment)->order;
                    $clientName = 'Client de passage';

                    if ($order) {
                        if ($order->partners_restaurant) {
                            $clientName = $order->partners_restaurant->full_name;
                            $clientType = 'Client partenaire';
                        } elseif ($order->free_client_for_restaurant) {
                            $clientName = $order->free_client_for_restaurant->full_name;
                            $clientType = 'Client gratuit';
                        } elseif (!empty($order->full_name)) {
                            $clientName = $order->full_name;
                            $clientType = 'Client débiteurs';
                        }
                    }

                    $regulation->resolved_client_name = $clientName;
                    $regulation->resolved_client_type = $clientType;

                    $regulation->order_total_amount = $slug
                        ? (float) $regulation->paymentLines->sum('amount')
                        : ($order ? (float) $order->total_order : 0);

                    return $regulation;
                })
                ->filter()
                ->groupBy('recouvrement_uuid')
                ->map(function ($items) use ($recouvrementTitle, $slug) {
                    return [
                        'recouvrement' => $items->first()->recouvrement,
                        'title'        => $recouvrementTitle,
                        'total_amount' => (float) $items->sum('amount'),
                        'clients'      => $items->groupBy('resolved_client_name')->map(function ($clientRegulations) use ($slug) {
                            $firstReg = $clientRegulations->first();
                            return [
                                'client_name' => $clientRegulations->first()->resolved_client_name,
                                'client_type' => $firstReg->resolved_client_type,
                                'items'       => $this->formatRecouvrementClientItems($clientRegulations, $slug),
                            ];
                        })->values(),
                    ];
                })->values();
        }

        if ($shouldFetchOtherCashIns) {
            $otherCashInsQuery = \App\Models\OtherCashIn::with([
                'creator:id,nom_utilisateur',
                'updater:id,nom_utilisateur',
                'regulationMethod:uuid,name',
                'medias',
            ])
                ->where('status', 'validated')
                ->whereDate('created_at', $date)
                ->whereNull('deleted_at');

            if ($slug) {
                if ($slug === 'AUTRES') {
                    $otherCashInsQuery->where(function ($q) {
                        $q->whereNull('slug')
                            ->orWhere('slug', '')
                            ->orWhere('slug', 'AUTRES ENCAISSEMENTS');
                    });
                } else {
                    $otherCashInsQuery->where('slug', $slug);
                }
            }

            if ($filterType === 'payment_method' && $request->filled('regulation_method_uuid')) {
                $otherCashInsQuery->where('regulation_method_uuid', $request->regulation_method_uuid);
            }

            if ($filterType === 'cashier_agent' && $createdBy) {
                $otherCashInsQuery->where('created_by', $createdBy);
            }

            $otherCashInsCollection = $otherCashInsQuery->orderByDesc('created_at')->get();

            if ($otherCashInsCollection->isNotEmpty()) {
                $otherCashIns = [
                    'title'        => $otherCashInTitle,
                    'total_amount' => (float) $otherCashInsCollection->sum('amount'),
                    'items'        => $otherCashInsCollection
                ];
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Flux de caisse récupéré avec succès',
            'data'    => [
                'date'           => $date,
                'slug'           => $slug,
                'creator'        => $creator,
                'expenses'       => $expenses,
                'receipts'       => $receipts,
                'recouvrements'  => $recouvrements,
                'other_cash_ins' => $otherCashIns
            ]
        ], 200);
    }

    /**
     * Helper spécifique pour structurer les items d'un recouvrement par client
     */
    private function formatRecouvrementClientItems($items, $slug = null)
    {
        return $items->map(function ($regulation) use ($slug) {
            $order = $regulation->payment?->order;
            $orderCode = $order?->code;
            $method = $regulation->method;

            $injectOrderCode = function($collection) use ($orderCode, $method) {
                if (!$collection) return [];
                return $collection->map(function ($item) use ($orderCode, $method) {
                    $item->order_code = $orderCode;
                    $item->payment_method = $method;
                    return $item;
                })->values();
            };

            $lines = $regulation->paymentLines ?? collect();

            $plats = $lines->filter(function ($line) {
                return $line->payable_type === 'App\Models\OrderMenuRestaurantItem'
                    || str_contains($line->payable_type, 'Item');
            })->map(function ($line) {
                return $line->payable;
            })->filter();

            $boissons = $lines->filter(function ($line) {
                return $line->payable_type === 'App\Models\OrderRestaurantDrink'
                    || str_contains($line->payable_type, 'Drink');
            })->map(function ($line) {
                return $line->payable;
            })->filter();

            // Calcule le total spécifiquement basé sur les lignes filtrées (ou sur paymentLines)
            $filteredTotal = $slug ? (float) $lines->sum('amount') : ($order ? (float) $order->total_order : 0);

            return [
                'uuid'                => $regulation->uuid,
                'amount'              => (float) $regulation->amount,
                'type'                => $regulation->type,
                'created_at'          => $regulation->created_at,
                'method'              => $regulation->method,
                'cash_receipt_family' => $regulation->cashReceiptFamily,
                'recouvrement'        => $regulation->recouvrement,
                'creator'             => $regulation->creator,
                'order_code'          => $orderCode,
                'order_total_price'   => $filteredTotal,
                'order_details'       => [
                    'plats'    => $injectOrderCode($plats),
                    'boissons' => $injectOrderCode($boissons)
                ]
            ];
        })->values();
    }

    /**
     * Helper privé pour formater les éléments de règlement (encaissements)
     */
    private function formatRegulationItems($items)
    {
        return $items->map(function ($regulation) {
            $order = $regulation->payment?->order;
            $orderCode = $order?->code;
            $method = $regulation->method;

            $injectOrderCode = function($collection) use ($orderCode, $method) {
                if (!$collection) return [];
                return $collection->map(function ($item) use ($orderCode, $method) {
                    $item->order_code = $orderCode;
                    $item->payment_method = $method;
                    return $item;
                })->values();
            };

            $lines = $regulation->paymentLines ?? collect();

            $plats = $lines->filter(function ($line) {
                return $line->payable_type === 'App\Models\OrderMenuRestaurantItem'
                    || str_contains($line->payable_type, 'Item');
            })->map(function ($line) {
                return $line->payable;
            })->filter();

            $boissons = $lines->filter(function ($line) {
                return $line->payable_type === 'App\Models\OrderRestaurantDrink'
                    || str_contains($line->payable_type, 'Drink');
            })->map(function ($line) {
                return $line->payable;
            })->filter();

            return [
                'uuid'                => $regulation->uuid,
                'amount'              => (float) $regulation->amount,
                'type'                => $regulation->type,
                'created_at'          => $regulation->created_at,
                'method'              => $regulation->method,
                'cash_receipt_family' => $regulation->cashReceiptFamily,
                'recouvrement'        => $regulation->recouvrement,
                'creator'             => $regulation->creator,
                'order_code'          => $orderCode,
                'order_total_price'   => $order ? (float) $order->total_order : 0,
                'order_details'       => [
                    'plats'    => $injectOrderCode($plats),
                    'boissons' => $injectOrderCode($boissons)
                ]
            ];
        })->values();
    }






    public function store_recouvrements(Request $request)
    {
        $auth = auth()->user();

        $request->validate([
            'order_menu_restaurant_uuid' => 'required|uuid',
            'total_amount' => 'required|numeric|min:0',
            'date' => 'nullable|date',
            'regulations' => 'required|array|min:1',
            'regulations.*.method_uuid' => 'required|uuid',
            'regulations.*.amount' => 'required|numeric|min:0.01',
            'regulations.*.lines' => 'nullable|array',
            'attachment' => 'nullable|file|max:2048|mimes:jpg,jpeg,png,svg,pdf'
        ]);

        $createdAt = $request->filled('date')
            ? Carbon::parse($request->date)->setTimeFrom(Carbon::now())
            : Carbon::now();

        $attachmentPath = null;
        if ($request->hasFile('attachment')) {
            $file = $request->file('attachment');
            $attachmentPath = $file->store('attachments/recouvrements', 'public');
        }

        DB::beginTransaction();

        try {

            $order = OrderMenuRestaurant::with([
                'items.paymentLines',
                'drinks.paymentLines',
                'free_client_for_restaurant',
                'partners_restaurant'
            ])->where('uuid', $request->order_menu_restaurant_uuid)->firstOrFail();


            $recouvrementRestoBar = Recouvrement::where('is_used_for_restaurant', true)->first();

            if (!$recouvrementRestoBar) {
                return response()->json([
                    'success' => false,
                    'message' => "Aucun type de recouvrement restaurant configuré (is_used_for_restaurant).",
                ], 422);
            }

            $payment = Payment::firstOrCreate(
                ['order_menu_restaurant_uuid' => $order->uuid],
                [
                    'paid_amount' => 0,
                    'remaining_amount' => (float) $order->total_order,
                    'status' => PaymentStatus::UNPAID->value,
                    'created_by' => auth()->id(),
                    'created_at' => $createdAt,
                ]
            );

            $payment->total_amount = (float) $order->total_order;
            $payment->save();

            $alreadyPaid = PaymentRegulation::where('payment_uuid', $payment->uuid)->whereNull('deleted_at')->sum('amount');

            $totalNewPaid = 0;
            $errors = [];

            $client = $order->free_client_for_restaurant
                ?? $order->partners_restaurant;

            $isClientAdvance = false;
            if ($client && isset($client->amount_allocated)) {
                $availableAdvance = (float) $client->amount_allocated;
                $isClientAdvance = true;
            } else {
                $availableAdvance = (float) ($order->amount_allocated ?? 0);
            }

            if ($availableAdvance <= 0) {
                return response()->json([
                    'success' => false,
                    'message' => "Montant des arrhes insuffisant. Veuillez recharger le solde.",
                ], 422);
            }


            foreach ($request->regulations as $index => $regulation) {

                $method = RegulationMethod::where('uuid', $regulation['method_uuid'])->first();

                if (!$method) {
                    $errors["regulations.$index.method_uuid"][] = "Méthode de règlement invalide";
                    continue;
                }

                if ($method->comment_required && empty($regulation['reference'])) {
                    $errors["regulations.$index.reference"][] = "La référence est obligatoire";
                }

                if ($method->phone_method && empty($regulation['phone_number'])) {
                    $errors["regulations.$index.phone_number"][] = "Le numéro est obligatoire";
                }

                if ($method->comment_required && empty($regulation['detail'])) {
                    $errors["regulations.$index.detail"][] = "Le commentaire est obligatoire";
                }

                if (!isset($regulation['amount']) || !is_numeric($regulation['amount']) || $regulation['amount'] <= 0) {
                    $errors["regulations.$index.amount"][] = "Montant invalide";
                }

                $totalNewPaid += (float) $regulation['amount'];
            }

            if (!empty($errors)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Erreurs de validation',
                    'errors' => $errors
                ], 422);
            }

            if ($totalNewPaid > $availableAdvance) {
                return response()->json([
                    'success' => false,
                    'message' => "Montant des arrhes insuffisant. Disponible: {$availableAdvance}, demandé: {$totalNewPaid}. Veuillez recharger le solde.",
                ], 422);
            }

            $remainingToPay = max(0, (float) $payment->total_amount - $alreadyPaid);

            \Log::info('PAYMENT DEBUG', [
                'payment_total_amount' => $payment->total_amount,
                'already_paid' => $alreadyPaid,
                'remaining' => $remainingToPay,
                'request_total_amount' => $request->total_amount,
            ]);

            if ($totalNewPaid > ($remainingToPay + 0.01)) {
                return response()->json([
                    'success' => false,
                    'message' => "Montant supérieur au reste à payer ({$remainingToPay})",
                ], 422);
            }


            foreach ($request->regulations as $regulation) {

                $method = RegulationMethod::where('uuid', $regulation['method_uuid'])->first();
                $cashReceiptType = CashReceiptType::where('is_linked_to_turnover', true)->first();
                $recouvrementRestoBar = Recouvrement::where('is_used_for_restaurant', true)
                    ->first();

                $itemsAmount = 0;
                $drinksAmount = 0;
                $itemLines = [];
                $drinkLines = [];

                if (!empty($regulation['lines'])) {
                    foreach ($regulation['lines'] as $line) {
                        if ($line['type'] === 'item') {
                            $itemsAmount += (float) $line['amount'];
                            $itemLines[] = $line;
                        } elseif ($line['type'] === 'drink') {
                            $drinksAmount += (float) $line['amount'];
                            $drinkLines[] = $line;
                        }
                    }
                } else {
                    $itemsAmount = (float) $regulation['amount'];
                }

                if ($itemsAmount > 0) {
                    $restoFamily = CashReceiptFamily::where('indexation', 'Consommation Restaurant')->first();

                    $regulationModelResto = PaymentRegulation::create([
                        'payment_uuid' => $payment->uuid,
                        'regulation_method_uuid' => $method->uuid,
                        'cash_receipt_families_uuid' => $restoFamily?->uuid,
                        'cash_receipt_type_uuid' => $cashReceiptType?->uuid,
                        'recouvrement_uuid' => $recouvrementRestoBar->uuid,
                        'slug' => 'ENCAISSEMENT RESTO',
                        'amount' => $itemsAmount,
                        'attachment' => $attachmentPath,
                        'type' => 'recouvrement',
                        'phone_number' => $regulation['phone_number'] ?? null,
                        'reference' => $regulation['reference'] ?? null,
                        'detail' => $regulation['detail'] ?? null,
                        'created_by' => auth()->id(),
                        'updated_by' => auth()->id(),
                        'created_at' => $createdAt,
                        'updated_at' => $createdAt,
                    ]);

                    // Enregistrer les payment_lines liées au resto
                    foreach ($itemLines as $line) {
                        PaymentLine::create([
                            'payment_uuid' => $payment->uuid,
                            'payment_regulation_uuid' => $regulationModelResto->uuid,
                            'payable_type' => get_class($order->items()->getModel()),
                            'payable_uuid' => $line['uuid'],
                            'amount' => $line['amount'],
                            'slug' => 'RESTO',
                            'regulation_method_uuid' => $method->uuid,
                            'phone_number' => $regulation['phone_number'] ?? null,
                            'reference' => $regulation['reference'] ?? null,
                            'detail' => $regulation['detail'] ?? null,
                            'created_by' => auth()->id(),
                            'updated_by' => auth()->id(),
                            'created_at' => $createdAt,
                            'updated_at' => $createdAt,
                        ]);
                    }
                }

                // 3. Traitement de la partie BAR (si présente)
                if ($drinksAmount > 0) {
                    $barFamily = CashReceiptFamily::where('indexation', 'Consommation Bar')->first();

                    $regulationModelBar = PaymentRegulation::create([
                        'payment_uuid' => $payment->uuid,
                        'regulation_method_uuid' => $method->uuid,
                        'cash_receipt_families_uuid' => $barFamily?->uuid,
                        'cash_receipt_type_uuid' => $cashReceiptType?->uuid,
                        'recouvrement_uuid' => $recouvrementRestoBar->uuid,
                        'slug' => 'ENCAISSEMENT BAR',
                        'amount' => $drinksAmount,
                        'attachment' => $attachmentPath,
                        'type' => 'recouvrement',
                        'phone_number' => $regulation['phone_number'] ?? null,
                        'reference' => $regulation['reference'] ?? null,
                        'detail' => $regulation['detail'] ?? null,
                        'created_by' => auth()->id(),
                        'updated_by' => auth()->id(),
                        'created_at' => $createdAt,
                        'updated_at' => $createdAt,
                    ]);

                    // Enregistrer les payment_lines liées au bar
                    foreach ($drinkLines as $line) {
                        PaymentLine::create([
                            'payment_uuid' => $payment->uuid,
                            'payment_regulation_uuid' => $regulationModelBar->uuid,
                            'payable_type' => get_class($order->drinks()->getModel()),
                            'payable_uuid' => $line['uuid'],
                            'amount' => $line['amount'],
                            'slug' => 'BAR',
                            'regulation_method_uuid' => $method->uuid,
                            'phone_number' => $regulation['phone_number'] ?? null,
                            'reference' => $regulation['reference'] ?? null,
                            'detail' => $regulation['detail'] ?? null,
                            'created_by' => auth()->id(),
                            'updated_by' => auth()->id(),
                            'created_at' => $createdAt,
                            'updated_at' => $createdAt,
                        ]);
                    }
                }
            }

            if ($request->hasFile('image_file')) {
                $file = $request->file('image_file');
                $filename = time() . '_' . $file->getClientOriginalName();
                $path = $file->store('products', 'public');

                $product->medias()->create([
                    'name' => $filename,
                    'disk' => 'public',
                    'path' => $path,
                    'filename' => $filename,
                    'mimetype' => $file->getClientMimeType(),
                    'extension' => $file->getClientOriginalExtension(),
                ]);
            }


            $totalPaid = $alreadyPaid + $totalNewPaid;

            $payment->paid_amount = $totalPaid;
            $payment->remaining_amount = max(0, $payment->total_amount - $totalPaid);

            if ($totalPaid <= 0) {
                $payment->status = PaymentStatus::UNPAID->value;
            } elseif ($totalPaid < $payment->total_amount) {
                $payment->status = PaymentStatus::PARTIALLY_PAID->value;
            } else {
                $payment->status = PaymentStatus::PAID->value;
            }

            $payment->save();

            $order->updated_by = auth()->id();
            $order->is_recouvrement = true;
            $order->save();


            $usedAdvance = min($availableAdvance, $totalNewPaid);

            if ($usedAdvance > 0) {
                if ($client) {
                    $client->decrement('amount_allocated', $usedAdvance);
                } else {
                    $order->decrement('amount_allocated', $usedAdvance);
                }
            }

            $order->refresh();

            $this->refreshLinesPaymentStatus($order);

            $order->refresh();

            $this->refreshPaymentStatus($order);

            $order->update([
                'updated_by' => $auth->id,
            ]);


            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Recouvrement éffectué avec succès',
                'data' => $payment->load('order')
            ]);

        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }


    public function cancel_recouvrements(Request $request, $uuid)
    {
        $auth = auth()->user();

        $request->validate([
            'type' => 'required|in:item,drink,order,regulation',
            'password' => 'required|string'
        ]);

        if (!Hash::check($request->password, $auth->password)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Mot de passe incorrect.'
            ], 422);
        }

        DB::beginTransaction();

        try {

            $payment = Payment::with(['order'])->where('uuid', $uuid)->firstOrFail();
            $order = $payment->order;

            $totalAmountToRefund = 0;

            if ($request->type === 'item') {
                $lines = PaymentLine::where('payable_uuid', $request->target_uuid)
                    ->where('payable_type', OrderMenuRestaurantItem::class)
                    ->where('payment_uuid', $payment->uuid)
                    ->get();

                if ($lines->isEmpty()) {
                    throw new \Exception("Aucun règlement trouvé pour cet item");
                }

                $refund = $lines->sum('amount');

                foreach ($lines as $line) {
                    $regulation = PaymentRegulation::where('uuid', $line->payment_regulation_uuid)
                        ->first();

                    if ($regulation) {
                        $regulation->amount = max(0, (float)$regulation->amount - (float)$line->amount);
                        if (round($regulation->amount, 2) <= 0) {
                            $regulation->delete();
                        } else {
                            $regulation->save();
                            $regulation->updated_by = auth()->id();
                        }
                    }
                    $line->delete();
                }

                $payment->paid_amount = max(0, $payment->paid_amount - $refund);

                OrderMenuRestaurantItem::where('uuid', $request->target_uuid)
                    ->update([
                        'regulation_status' => PaymentOrderItemStatus::NOT_PAID->value
                    ]);

                $totalAmountToRefund += $refund;
            }


            if ($request->type === 'drink') {
                $lines = PaymentLine::where('payable_uuid', $request->target_uuid)
                    ->where('payable_type', OrderRestaurantDrink::class)
                    ->where('payment_uuid', $payment->uuid)
                    ->get();

                if ($lines->isEmpty()) {
                    throw new \Exception("Aucun règlement trouvé pour ce drink");
                }

                $refund = $lines->sum('amount');

                foreach ($lines as $line) {
                    $regulation = PaymentRegulation::where('uuid', $line->payment_regulation_uuid)
                        ->first();

                    if ($regulation) {
                        $regulation->amount = max(0, (float)$regulation->amount - (float)$line->amount);
                        if (round($regulation->amount, 2) <= 0) {
                            $regulation->delete();
                        } else {
                            $regulation->save();
                            $regulation->updated_by = auth()->id();
                        }
                    }
                    $line->delete();
                }

                $payment->paid_amount = max(0, $payment->paid_amount - $refund);

                OrderRestaurantDrink::where('uuid', $request->target_uuid)
                    ->update([
                        'regulation_status' => PaymentOrderItemStatus::NOT_PAID->value
                    ]);

                $totalAmountToRefund += $refund;
            }


            if ($request->type === 'order') {
                $totalAmountToRefund = $payment->paid_amount;

                $paidItems = $order->items()
                    ->where('total_price', '>', 0)
                    ->whereIn('regulation_status', [
                        PaymentOrderItemStatus::PAID->value,
                        PaymentOrderItemStatus::PARTIALLY_PAID->value
                    ])
                    ->pluck('uuid');

                $paidDrinks = $order->drinks()
                    ->where('total_price', '>', 0)
                    ->whereIn('regulation_status', [
                        PaymentOrderItemStatus::PAID->value,
                        PaymentOrderItemStatus::PARTIALLY_PAID->value
                    ])
                    ->pluck('uuid');

                PaymentLine::where('payment_uuid', $payment->uuid)->delete();
                PaymentRegulation::where('payment_uuid', $payment->uuid)->delete();

                $order->items()->whereIn('uuid', $paidItems)->update([
                    'regulation_status' => PaymentOrderItemStatus::NOT_PAID->value
                ]);
                $order->drinks()->whereIn('uuid', $paidDrinks)->update([
                    'regulation_status' => PaymentOrderItemStatus::NOT_PAID->value
                ]);
                $hasOtherPaidItems = $order->items()
                    ->whereIn('regulation_status', [PaymentOrderItemStatus::PAID->value, PaymentOrderItemStatus::PARTIALLY_PAID->value])
                    ->exists();

                $hasOtherPaidDrinks = $order->drinks()
                    ->whereIn('regulation_status', [PaymentOrderItemStatus::PAID->value, PaymentOrderItemStatus::PARTIALLY_PAID->value])
                    ->exists();

                $order->regulation_status = ($hasOtherPaidItems || $hasOtherPaidDrinks)
                    ? PaymentOrderMenusStatus::PARTIALLY_PAID->value
                    : PaymentOrderMenusStatus::NOT_PAID->value;
                $order->updated_by = auth()->id();
                $order->save();

                $client = $order->free_client_for_restaurant ?? $order->partners_restaurant;
                if ($client && $totalAmountToRefund > 0) {
                    $client->increment('amount_allocated', $totalAmountToRefund);
                } else {
                    $order->increment('amount_allocated', $totalAmountToRefund);
                }

                $payment->delete();
                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'Annulation complète effectuée'
                ]);
            }


            $payment->paid_amount = max(0, $payment->paid_amount);
            $payment->remaining_amount = max(0, $payment->total_amount - $payment->paid_amount);

            if ($payment->paid_amount <= 0) {
                $payment->status = PaymentStatus::UNPAID->value;
            } elseif ($payment->paid_amount < $payment->total_amount) {
                $payment->status = PaymentStatus::PARTIALLY_PAID->value;
            } else {
                $payment->status = PaymentStatus::PAID->value;
            }
            $payment->save();


            $order->refresh();
            $this->refreshLinesPaymentStatus($order);
            $order->refresh();
            $this->refreshPaymentStatus($order);

            if ($totalAmountToRefund > 0) {
                $client = $order->free_client_for_restaurant ?? $order->partners_restaurant;
                if ($client) {
                    $client->increment('amount_allocated', $totalAmountToRefund);
                } else {
                    $order->increment('amount_allocated', $totalAmountToRefund);
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Annulation effectuée avec succès'
            ]);

        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }


    public function destroy($uuid)
    {
        return $this->destroyRegulation(request(), $uuid);
    }

}
