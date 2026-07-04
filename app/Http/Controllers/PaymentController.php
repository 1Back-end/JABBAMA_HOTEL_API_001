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
            'regulations' => 'required|array|min:1',
            'regulations.*.method_uuid' => 'required|uuid',
            'regulations.*.amount' => 'required|numeric|min:0.01',
            'regulations.*.lines' => 'nullable|array',
        ]);

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
                ]
            );

            $payment->total_amount = (float) $order->total_order;
            $payment->save();

            $alreadyPaid = PaymentRegulation::where('payment_uuid', $payment->uuid)
                ->whereNull('deleted_at')
                ->sum('amount');

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
                $cashReceiptType = CashReceiptType::where('is_linked_to_turnover', true)
                    ->first();

                $hasItems = false;
                $hasDrinks = false;

                if (!empty($regulation['lines'])) {
                    foreach ($regulation['lines'] as $line) {
                        if ($line['type'] === 'item') {
                            $hasItems = true;
                        }

                        if ($line['type'] === 'drink') {
                            $hasDrinks = true;
                        }
                    }
                }

                if ($hasItems && $hasDrinks) {
                    $indexationName = 'Consommation Bar / Restaurant';
                } elseif ($hasItems) {
                    $indexationName = 'Consommation Restaurant';
                } elseif ($hasDrinks) {
                    $indexationName = 'Consommation Bar';
                } else {
                    $indexationName = null;
                }

                $cashReceiptFamily = $indexationName
                    ? CashReceiptFamily::where('indexation', $indexationName)->first()
                    : null;

                $reg = PaymentRegulation::create([
                    'payment_uuid' => $payment->uuid,
                    'regulation_method_uuid' => $method->uuid,
                    'cash_receipt_families_uuid'  => $cashReceiptFamily?->uuid,
                    'cash_receipt_type_uuid' => $cashReceiptType?->uuid,
                    'amount' => (float) $regulation['amount'],
                    'phone_number' => $regulation['phone_number'] ?? null,
                    'reference' => $regulation['reference'] ?? null,
                    'detail' => $regulation['detail'] ?? null,
                    'created_by' => auth()->id(),
                    'updated_by' => auth()->id(),
                ]);


                if (!empty($regulation['lines'])) {
                    foreach ($regulation['lines'] as $line) {
                        $payableModel = $line['type'] === 'item'
                            ? get_class($order->items()->getModel())
                            : get_class($order->drinks()->getModel());

                        PaymentLine::create([
                            'payment_uuid' => $payment->uuid,
                            'payable_type' => $payableModel,
                            'payable_uuid' => $line['uuid'],
                            'amount' => $line['amount'],
                            'regulation_method_uuid' => $method->uuid,
                            'phone_number' => $regulation['phone_number'] ?? null,
                            'reference' => $regulation['reference'] ?? null,
                            'detail' => $regulation['detail'] ?? null,
                            'created_by' => auth()->id(),
                            'updated_by' => auth()->id(),
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
                    $regulation = PaymentRegulation::where('payment_uuid', $payment->uuid)
                        ->where('regulation_method_uuid', $line->regulation_method_uuid)
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
                    $regulation = PaymentRegulation::where('payment_uuid', $payment->uuid)
                        ->where('regulation_method_uuid', $line->regulation_method_uuid)
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
        $perPage = (int) $request->input('limit', 25);
        $page = (int) $request->input('page', 1);

        $date = $request->filled('date')
            ? Carbon::parse($request->date)->toDateString()
            : Carbon::today()->toDateString();

        $query = PaymentRegulation::query()
            ->join(
                'regulation_methods',
                'payment_regulations.regulation_method_uuid',
                '=',
                'regulation_methods.uuid'
            )
            ->select([
                DB::raw('MIN(payment_regulations.uuid) as uuid'),
                DB::raw('DATE(payment_regulations.created_at) as date'),
                DB::raw('MIN(payment_regulations.created_at) as created_at'),
                'payment_regulations.created_by',
                'regulation_methods.uuid as regulation_method_uuid',
                'regulation_methods.name as regulation_method_name',
                'payment_regulations.type',
                DB::raw('SUM(payment_regulations.amount) as total_amount'),
            ])
            ->with(['creator:id,nom_utilisateur'])
            ->whereDate('payment_regulations.created_at', $date)
            ->groupBy(
                DB::raw('DATE(payment_regulations.created_at)'),
                'payment_regulations.created_by',
                'regulation_methods.uuid',
                'regulation_methods.name',
                'payment_regulations.type'
            )
            ->orderByDesc(DB::raw('DATE(payment_regulations.created_at)'));

        $totalsQuery = PaymentRegulation::whereDate('created_at', $date);

        if ($request->cash_register_filter_type === CashRegisterFilterType::PAYMENT_TYPE->value && $request->filled('cash_receipt_type_uuid')) {
            $query->where('cash_receipt_type_uuid', $request->cash_receipt_type_uuid);
            $totalsQuery->where('cash_receipt_type_uuid', $request->cash_receipt_type_uuid);
        }

        if ($request->cash_register_filter_type === CashRegisterFilterType::PAYMENT_METHOD->value && $request->filled('regulation_method_uuid')) {
            $query->where('regulation_method_uuid', $request->regulation_method_uuid);
            $totalsQuery->where('regulation_method_uuid', $request->regulation_method_uuid);
        }

        if ($request->cash_register_filter_type === CashRegisterFilterType::EXPENSE_TYPE->value && $request->filled('restaurant_expense_type_uuid')) {
            $query->where('restaurant_expense_type_uuid', $request->restaurant_expense_type_uuid);
            $totalsQuery->where('restaurant_expense_type_uuid', $request->restaurant_expense_type_uuid);
        }

        if ($request->cash_register_filter_type === CashRegisterFilterType::CASHIER_AGENT->value && $request->filled('created_by')) {
            $query->where('payment_regulations.created_by', $request->created_by);
            $totalsQuery->where('payment_regulations.created_by', $request->created_by);
        }

        $data = $query->paginate($perPage, ['*'], 'page', $page);

        $totals = $totalsQuery->select('type', DB::raw('SUM(amount) as total'))
            ->groupBy('type')
            ->get()
            ->keyBy('type');

        $totalEncaissements = (float) ($totals->get('encaissement')?->total ?? 0);
        $totalDepenses      = (float) ($totals->get('expense')?->total ?? 0);
        $soldeNet           = $totalEncaissements - $totalDepenses;

        return response()->json([
            'success'             => true,
            'data'                => $data->items(),
            'current_page'        => $data->currentPage(),
            'last_page'           => $data->lastPage(),
            'per_page'            => $data->perPage(),
            'total'               => $data->total(),
            'total_encaissements' => $totalEncaissements,
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


    public function show_global_cashflow_by_user_today(string $userId)
    {
        $today = Carbon::today()->toDateString();

        $expenses = ExpensePayment::with([
            'creator:id,nom_utilisateur', 'updater:id,nom_utilisateur',
            'expenseType:uuid,name', 'family:uuid,name', 'method:uuid,name',
        ])
            ->where('created_by', $userId)
            ->whereDate('paid_at', $today)
            ->orderByDesc('paid_at')
            ->get()
            ->groupBy('restaurant_expense_type_uuid')
            ->map(function ($items) {
                return [
                    'expense_type' => $items->first()->expenseType,
                    'total_amount' => $items->sum('amount'),
                    'items' => $items->values()
                ];
            })->values();


        $receipts = PaymentRegulation::with([
            'creator:id,nom_utilisateur', 'updater:id,nom_utilisateur',
            'cashReceiptType:uuid,name', 'cashReceiptFamily:uuid,name', 'method:uuid,name',
        ])
            ->where('created_by', $userId)
            ->where('type', 'encaissement')
            ->whereDate('created_at', $today)
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('cash_receipt_type_uuid')
            ->map(function ($items) {
                return [
                    'receipt_type' => $items->first()->cashReceiptType,
                    'total_amount' => $items->sum('amount'),
                    'items'        => $items->values()
                ];
            })->values();

        return response()->json([
            'success' => true,
            'message' => 'Flux de caisse du jour récupéré avec succès',
            'data'    => [
                'expenses' => $expenses,
                'receipts' => $receipts
            ]
        ], 200);
    }


    public function destroy($uuid)
    {
        return $this->destroyRegulation(request(), $uuid);
    }

}
