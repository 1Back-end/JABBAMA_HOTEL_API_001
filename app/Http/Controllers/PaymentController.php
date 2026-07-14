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

                $regulationModel  = PaymentRegulation::create([
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
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ]);


                if (!empty($regulation['lines'])) {
                    foreach ($regulation['lines'] as $line) {
                        $payableModel = $line['type'] === 'item'
                            ? get_class($order->items()->getModel())
                            : get_class($order->drinks()->getModel());

                        PaymentLine::create([
                            'payment_uuid' => $payment->uuid,
                            'payment_regulation_uuid' => $regulationModel->uuid,
                            'payable_type' => $payableModel,
                            'payable_uuid' => $line['uuid'],
                            'amount' => $line['amount'],
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
        $perPage = (int) $request->input('limit', 25);
        $page = (int) $request->input('page', 1);

        $date = $request->filled('date')
            ? Carbon::parse($request->date)->toDateString()
            : Carbon::today()->toDateString();

        $relations = ['creator:id,nom_utilisateur'];

        $selectColumns = [
            DB::raw('MIN(uuid) as uuid'),
            DB::raw('DATE(created_at) as date'),
            DB::raw('MIN(created_at) as created_at'),
        ];

        $groupByColumns = [
            DB::raw('DATE(created_at)'),
        ];

        $query = PaymentRegulation::query();
        $totalsQuery = PaymentRegulation::whereDate('created_at', $date);

        if ($request->cash_register_filter_type === CashRegisterFilterType::PAYMENT_METHOD->value) {
            // Mode de règlement : Fusionné
            $selectColumns[] = 'regulation_method_uuid';
            $selectColumns[] = DB::raw("SUM(CASE WHEN type = 'encaissement' THEN amount ELSE 0 END) as total_encaissements");
            $selectColumns[] = DB::raw("SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) as total_depenses");
            $selectColumns[] = DB::raw("SUM(CASE WHEN type = 'encaissement' THEN amount ELSE -amount END) as total_amount");

            $groupByColumns[] = 'regulation_method_uuid';
            $relations[] = 'method:uuid,name';

            if ($request->filled('regulation_method_uuid')) {
                $query->where('regulation_method_uuid', $request->regulation_method_uuid);
                $totalsQuery->where('regulation_method_uuid', $request->regulation_method_uuid);
            }

        } elseif ($request->cash_register_filter_type === CashRegisterFilterType::PAYMENT_TYPE->value) {
            $selectColumns[] = 'cash_receipt_type_uuid';
            $selectColumns[] = 'restaurant_expense_type_uuid';
            $selectColumns[] = DB::raw("SUM(CASE WHEN type = 'encaissement' THEN amount ELSE 0 END) as total_encaissements");
            $selectColumns[] = DB::raw("SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END) as total_depenses");
            $selectColumns[] = DB::raw("SUM(CASE WHEN type = 'encaissement' THEN amount ELSE -amount END) as total_amount");

            $groupByColumns[] = 'cash_receipt_type_uuid';
            $groupByColumns[] = 'restaurant_expense_type_uuid';
            $relations[] = 'cashReceiptType:uuid,name';
            $relations[] = 'expenseType:uuid,name';

            if ($request->filled('cash_receipt_type_uuid')) {
                $query->where('cash_receipt_type_uuid', $request->cash_receipt_type_uuid);
                $totalsQuery->where('cash_receipt_type_uuid', $request->cash_receipt_type_uuid);
            }

            if ($request->filled('restaurant_expense_type_uuid')) {
                $query->where('restaurant_expense_type_uuid', $request->restaurant_expense_type_uuid);
                $totalsQuery->where('restaurant_expense_type_uuid', $request->restaurant_expense_type_uuid);
            }

        } else {
            $selectColumns[] = 'created_by';
            $selectColumns[] = 'type';
            $selectColumns[] = DB::raw('SUM(amount) as total_amount');

            $groupByColumns[] = 'created_by';
            $groupByColumns[] = 'type';

            if ($request->cash_register_filter_type === CashRegisterFilterType::CASHIER_AGENT->value && $request->filled('created_by')) {
                $query->where('created_by', $request->created_by);
                $totalsQuery->where('created_by', $request->created_by);
            }
        }

        $data = $query->select($selectColumns)
            ->with($relations)
            ->whereDate('created_at', $date)
            ->whereNull('deleted_at')
            ->groupBy($groupByColumns)
            ->orderByDesc(DB::raw('DATE(created_at)'))
            ->paginate($perPage, ['*'], 'page', $page);

        // 4. Calcul des totaux de synthèse pour la carte globale
        $totals = $totalsQuery->select('type', DB::raw('SUM(amount) as total'))
            ->whereNull('deleted_at')
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

    private function buildExpenseTree($items)
    {
        $tree = [];

        foreach ($items as $item) {

            $current = &$tree;

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

        $expensesQuery = ExpensePayment::with([
            'creator:id,nom_utilisateur',
            'updater:id,nom_utilisateur',
            'expenseType:uuid,name',
            'family:uuid,name',
            'method:uuid,name',
        ])
            ->where('status', 'paid')
            ->whereDate('paid_at', $date)
            ->whereNull('deleted_at');

        if (
            ($filterType === 'expense_type' || $filterType === 'payment_type') &&
            $request->filled('restaurant_expense_type_uuid')
        ) {
            $expensesQuery->where('restaurant_expense_type_uuid', $request->restaurant_expense_type_uuid);
        }

        if ($filterType === 'payment_method' && $request->filled('regulation_method_uuid')) {
            $expensesQuery->whereHas('method', function ($query) use ($request) {
                $query->where('uuid', $request->regulation_method_uuid);
            });
        }

        if ($filterType === 'cashier_agent' && $request->filled('created_by')) {
            $expensesQuery->where('created_by', $request->created_by);
        }

        $expenses = $expensesQuery->orderByDesc('paid_at')
            ->get()
            ->groupBy('restaurant_expense_type_uuid')
            ->map(function ($items) {
                return [
                    'expense_type' => $items->first()->expenseType,
                    'total_amount' => (float) $items->sum('amount'),
                    'families'     => $this->buildExpenseTree($items),
                ];
            })
            ->values();


        $receiptsQuery = PaymentRegulation::with([
            'creator:id,nom_utilisateur',
            'updater:id,nom_utilisateur',
            'cashReceiptType:uuid,name',
            'cashReceiptFamily:uuid,name',
            'method:uuid,name',
            'payment.order.items.menu:uuid,name',
            'payment.order.drinks.drinkConfig.product',
        ])
            ->where('type', 'encaissement')
            ->whereDate('created_at', $date)
            ->whereNull('deleted_at');

        if ($filterType === 'payment_type' && $request->filled('cash_receipt_type_uuid')) {
            $receiptsQuery->where('cash_receipt_type_uuid', $request->cash_receipt_type_uuid);
        }

        if ($filterType === 'expense_type' && $request->filled('restaurant_expense_type_uuid')) {
            $expensesQuery->where('restaurant_expense_type_uuid', $request->restaurant_expense_type_uuid);
        }

        if ($filterType === 'payment_method' && $request->filled('regulation_method_uuid')) {
            $receiptsQuery->whereHas('method', function ($query) use ($request) {
                $query->where('uuid', $request->regulation_method_uuid);
            });
        }

        if ($filterType === 'cashier_agent' && $request->filled('created_by')) {
            $receiptsQuery->where('created_by', $request->created_by);
        }

        $receipts = $receiptsQuery->orderByDesc('created_at')
            ->get()
            ->groupBy('cash_receipt_type_uuid')
            ->map(function ($items) {
                return [
                    'receipt_type' => $items->first()->cashReceiptType,
                    'total_amount' => $items->sum('amount'),
                    'items'        => $items->map(function ($regulation) {
                        $order = $regulation->payment?->order;
                        $orderCode = $order?->code;

                        $injectOrderCode = function($collection) use ($orderCode) {
                            if (!$collection) return [];
                            return $collection->map(function ($item) use ($orderCode) {
                                $item->order_code = $orderCode;
                                return $item;
                            })->values();
                        };

                        if ($regulation->cashReceiptType?->name === 'ENCAISSEMENT RESTO/BAR') {
                            $plats = $order?->items;
                            $boissons = $order?->drinks;
                        } else {
                            $familyUpper = strtoupper($regulation->cashReceiptFamily?->name ?? '');
                            $plats = str_contains($familyUpper, 'RESTO') ? $order?->items : null;
                            $boissons = str_contains($familyUpper, 'BAR') ? $order?->drinks : null;
                        }

                        return [
                            'uuid'                        => $regulation->uuid,
                            'amount'                      => $regulation->amount,
                            'type'                        => $regulation->type,
                            'created_at'                  => $regulation->created_at,
                            'method'                      => $regulation->method,
                            'cash_receipt_family'         => $regulation->cashReceiptFamily,
                            'creator'                     => $regulation->creator,
                            'order_code'                  => $orderCode,
                            'order_total_price'           => $order?->total_order,
                            'order_details' => [
                                'plats'    => $injectOrderCode($plats),
                                'boissons' => $injectOrderCode($boissons)
                            ]
                        ];
                    })->values()
                ];
            })->values();

        return response()->json([
            'success' => true,
            'message' => 'Flux de caisse récupéré avec succès',
            'data'    => [
                'date'     => $date,
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
