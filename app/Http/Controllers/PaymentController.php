<?php

namespace App\Http\Controllers;

use App\Enums\CashRegisterFilterType;
use App\Enums\ExpenseTitleEnum;
use App\Enums\MenuOrderStatus;
use App\Enums\OrderMenuRestaurantItemStatus;
use App\Enums\PaymentOrderItemStatus;
use App\Enums\PaymentOrderMenusStatus;
use App\Enums\PaymentRegulationSlug;
use App\Enums\PaymentStatus;
use App\Enums\PdgCategory;
use App\Enums\RestaurantExpenseSlug;
use App\Enums\RestaurantRubricEnum;
use App\Enums\TypeClientsForPaiment;
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
use App\Models\RoomService;
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

        $isRoomServiceActive = ($order->is_room_service);
        $roomServiceTotal = $isRoomServiceActive ? (float) str_replace(',', '.', $order->price_for_room_service ?? 0) * (int) ($order->quantity_for_room_service ?? 0) : 0.0;

        $roomServicePaid = 0.0;
        if ($isRoomServiceActive && $order->room_service_uuid) {
            $roomServicePaid = (float) \DB::table('payment_lines')
                ->where('payable_type', \App\Models\RoomService::class)
                ->where('payable_uuid', $order->room_service_uuid)
                ->whereNull('deleted_at')
                ->sum('amount');
        }

        $roomServiceStatus = 'not_paid';
        if ($roomServiceTotal > 0) {
            if ($roomServicePaid >= $roomServiceTotal) {
                $roomServiceStatus = 'paid';
            } elseif ($roomServicePaid > 0) {
                $roomServiceStatus = 'partially_paid';
            }
        }

        if ($allLines->isEmpty() && $roomServiceTotal <= 0) {
            $order->regulation_status = PaymentOrderMenusStatus::NOT_PAID->value;
            $order->save();
            return;
        }

        $statuses = $allLines->pluck('regulation_status')->toArray();
        if ($roomServiceTotal > 0) {
            $statuses[] = $roomServiceStatus;
        }

        if (!empty($statuses) && collect($statuses)->every(fn($status) => $status === 'paid')) {
            $order->regulation_status = PaymentOrderMenusStatus::PAID->value;
        }
        elseif (!empty($statuses) && collect($statuses)->every(fn($status) => $status === 'not_paid')) {
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
            'drinks.paymentLines',
            'roomService.paymentLines'
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
        if ($order->is_room_service && $order->roomService) {
            $roomService = $order->roomService;
            $totalRoomService = (float) str_replace(',', '.', $roomService->price_for_room_service ?? 0) * (int) ($roomService->quantity_for_room_service ?? 0);
            if ($totalRoomService === 0.0) {
            } else {
                $paidRoomService = (float) $roomService->paymentLines->sum('amount');
            }
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

                $itemsAmount = 0;
                $drinksAmount = 0;
                $roomServiceAmount = 0;

                $itemLines = [];
                $drinkLines = [];
                $roomServiceLines = [];

                if (!empty($regulation['lines'])) {
                    foreach ($regulation['lines'] as $line) {
                        if ($line['type'] === 'item') {
                            $itemsAmount += (float) $line['amount'];
                            $itemLines[] = $line;
                        } elseif ($line['type'] === 'drink') {
                            $drinksAmount += (float) $line['amount'];
                            $drinkLines[] = $line;
                        } elseif ($line['type'] === 'room_service') {
                            $roomServiceAmount += (float) $line['amount'];
                            $roomServiceLines[] = $line;
                        }
                    }
                } else {
                    $itemsAmount = (float) $regulation['amount'];
                }

                if ($roomServiceAmount > 0) {
                    $hasItems = $order->items()->count() > 0;
                    if ($hasItems) {
                        $itemsAmount += $roomServiceAmount;
                        $roomServiceLinesSrc = $roomServiceLines;
                    } else {
                        $drinksAmount += $roomServiceAmount;
                    }
                }

                if ($itemsAmount > 0) {
                    $restoFamily = CashReceiptFamily::where('indexation', \App\Enums\CashReceiptType::CONSOMMATION_RESTAURANT->value)->first();

                    $regulationModelResto = PaymentRegulation::create([
                        'payment_uuid' => $payment->uuid,
                        'regulation_method_uuid' => $method->uuid,
                        'cash_receipt_families_uuid' => $restoFamily?->uuid,
                        'cash_receipt_type_uuid' => $cashReceiptType?->uuid,
                        'slug' => PaymentRegulationSlug::ENCAISSEMENT_RESTO->value,
                        'amount' => $itemsAmount,
                        'phone_number' => $regulation['phone_number'] ?? null,
                        'reference' => $regulation['reference'] ?? null,
                        'detail' => $regulation['detail'] ?? null,
                        'created_by' => auth()->id(),
                        'updated_by' => auth()->id(),
                        'created_at' => $createdAt,
                        'updated_at' => $createdAt,
                    ]);

                    foreach ($itemLines as $line) {
                        PaymentLine::create([
                            'payment_uuid' => $payment->uuid,
                            'payment_regulation_uuid' => $regulationModelResto->uuid,
                            'payable_type' => get_class($order->items()->getModel()),
                            'payable_uuid' => $line['uuid'],
                            'amount' => $line['amount'],
                            'slug' => RestaurantExpenseSlug::RESTO->value,
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

                    if ($order->items()->count() > 0) {
                        foreach ($roomServiceLines as $line) {
                            PaymentLine::create([
                                'payment_uuid' => $payment->uuid,
                                'payment_regulation_uuid' => $regulationModelResto->uuid,
                                'payable_type' => RoomService::class,
                                'payable_uuid' => $line['uuid'],
                                'amount' => $line['amount'],
                                'slug' => RestaurantExpenseSlug::RESTO->value,
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

                if ($drinksAmount > 0) {
                    $barFamily = CashReceiptFamily::where('indexation', \App\Enums\CashReceiptType::CONSOMMATION_BAR->value)->first();

                    $regulationModelBar = PaymentRegulation::create([
                        'payment_uuid' => $payment->uuid,
                        'regulation_method_uuid' => $method->uuid,
                        'cash_receipt_families_uuid' => $barFamily?->uuid,
                        'cash_receipt_type_uuid' => $cashReceiptType?->uuid,
                        'slug' => PaymentRegulationSlug::ENCAISSEMENT_RESTO->value,
                        'amount' => $drinksAmount,
                        'phone_number' => $regulation['phone_number'] ?? null,
                        'reference' => $regulation['reference'] ?? null,
                        'detail' => $regulation['detail'] ?? null,
                        'created_by' => auth()->id(),
                        'updated_by' => auth()->id(),
                        'created_at' => $createdAt,
                        'updated_at' => $createdAt,
                    ]);

                    foreach ($drinkLines as $line) {
                        PaymentLine::create([
                            'payment_uuid' => $payment->uuid,
                            'payment_regulation_uuid' => $regulationModelBar->uuid,
                            'payable_type' => get_class($order->drinks()->getModel()),
                            'payable_uuid' => $line['uuid'],
                            'amount' => $line['amount'],
                            'slug' => RestaurantExpenseSlug::BAR->value,
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

                    if ($order->items()->count() === 0) {
                        foreach ($roomServiceLines as $line) {
                            PaymentLine::create([
                                'payment_uuid' => $payment->uuid,
                                'payment_regulation_uuid' => $regulationModelBar->uuid,
                                'payable_type' => RoomService::class,
                                'payable_uuid' => $line['uuid'],
                                'amount' => $line['amount'],
                                'slug' => RestaurantExpenseSlug::BAR->value,
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
            'type' => 'required|in:item,drink,room_service,order,regulation',
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

            if ($request->type === 'room_service') {
                $lines = PaymentLine::where('payable_uuid', $request->target_uuid)
                    ->where('payable_type', \App\Models\RoomService::class)
                    ->where('payment_uuid', $payment->uuid)
                    ->get();

                if ($lines->isEmpty()) {
                    throw new \Exception("Aucun règlement trouvé pour ce room service");
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
                if ($order->is_room_service === true) {
                    $order->room_service_payment_lines = [];
                    $order->save();
                }
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

        $query = PaymentRegulation::query()->whereNotNull('payment_regulations.slug');
        $totalsQuery = PaymentRegulation::whereDate('created_at', $date)->whereNotNull('slug');

        if ($request->cash_register_filter_type === CashRegisterFilterType::PAYMENT_METHOD->value) {

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

    private function buildOtherCashInTree($items)
    {
        $tree = [];

        foreach ($items as $item) {
            $current = &$tree;

            $familyUuids = $item->family_hierarchy_uuids ?? [];

            if ($item->cash_receipt_family_uuid && !in_array($item->cash_receipt_family_uuid, $familyUuids)) {
                $familyUuids[] = $item->cash_receipt_family_uuid;
            }

            if (empty($familyUuids)) {
                $typeName = optional($item->cashReceiptFamily)->name ?? 'AUTRES ENCAISSEMENTS';
                $typeUuid = optional($item->cashReceiptFamily)->uuid ?? null;
                $defaultKey = 'direct_type_' . ($typeUuid ?? 'general');

                if (!isset($current[$defaultKey])) {
                    $current[$defaultKey] = [
                        'uuid'     => $typeUuid,
                        'name'     => $typeName,
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
                    'method' => $item->regulationMethod,
                ];
                continue;
            }

            $families = \App\Models\CashReceiptFamily::whereIn('uuid', $familyUuids)
                ->get()
                ->sortBy(function ($fam) use ($familyUuids) {
                    return array_search($fam->uuid, $familyUuids);
                })
                ->values();

            foreach ($families as $index => $family) {
                $uuid = $family->uuid;
                $isLast = ($index === count($families) - 1);

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
                if ($isLast) {
                    $itemData = [
                        'uuid'   => $item->uuid,
                        'name'   => $item->name,
                        'amount' => (float) $item->amount,
                        'method' => $item->regulationMethod,
                    ];

                    $exists = collect($current[$uuid]['items'])->contains('uuid', $item->uuid);
                    if (!$exists) {
                        $current[$uuid]['items'][] = $itemData;
                    }
                }

                $current = &$current[$uuid]['children'];
            }

            unset($current);
        }

        return $this->normalizeTree($tree);
    }


    public function show_global_cashflow_today(Request $request)
    {
        $date = $request->filled('date') ? Carbon::parse($request->date)->toDateString() : null;
        $filterType = $request->cash_register_filter_type;
        $createdBy = $request->filled('created_by') ? $request->created_by : null;

        // Récupération sécurisée du slug (NULL si non envoyé)
        $slug = $request->filled('slug') ? strtoupper(trim($request->slug)) : null;

        $creator = $createdBy ? \App\Models\User::select('id', 'nom_utilisateur')->find($createdBy) : null;

        $slugLabel         = $slug ? strtoupper($slug) : 'GLOBAL';
        $titleEnum         = ExpenseTitleEnum::fromSlug($slug);
        $expenseTitle      = $titleEnum->getExpenseTitle($slugLabel);
        $otherCashInTitle  = $titleEnum->getOtherCashInTitle($slugLabel);
        $receiptTitle      = $titleEnum->getReceiptTitle($slugLabel);
        $recouvrementTitle = $titleEnum->getRecouvrementTitle($slugLabel);

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

        // 1. Dépenses
        if ($shouldFetchExpenses) {
            $expensesQuery = ExpensePayment::with([
                'creator:id,nom_utilisateur',
                'updater:id,nom_utilisateur',
                'expenseType:uuid,name,slug',
                'family:uuid,name',
                'method:uuid,name',
            ])
                ->where('status', 'paid')
                ->when($date, fn($q) => $q->whereDate('paid_at', $date))
                ->whereNull('deleted_at');

            // Filtrer par slug seulement s'il est présent
            if ($slug) {
                if ($slug === ExpenseTitleEnum::AUTRE->value) {
                    $expensesQuery->where(function ($q) {
                        $q->whereNull('slug')
                            ->orWhere('slug', '')
                            ->orWhere('slug', PdgCategory::AUTRES_DEPENSES->value);
                    });
                } else {
                    $expensesQuery->where('slug', $slug);
                }
            }

            if ($request->filled('restaurant_expense_type_uuid')) {
                $expensesQuery->where('restaurant_expense_type_uuid', $request->restaurant_expense_type_uuid);
            }

            if ($filterType === 'payment_method' && $request->filled('regulation_method_uuid')) {
                $expensesQuery->whereHas('method', fn($q) => $q->where('uuid', $request->regulation_method_uuid));
            }

            if ($filterType === 'cashier_agent' && $createdBy) {
                $expensesQuery->where('created_by', $createdBy);
            }

            $expenses = $expensesQuery->orderByDesc('paid_at')
                ->get()
                ->groupBy('restaurant_expense_type_uuid')
                ->map(fn($items) => [
                    'expense_type' => $items->first()->expenseType,
                    'title'        => $expenseTitle,
                    'total_amount' => (float) $items->sum('amount'),
                    'families'     => $this->buildExpenseTree($items),
                ])
                ->values();
        }

        // 2. Encaissements
        if ($shouldFetchReceipts) {
            $receiptsQuery = PaymentRegulation::with([
                'creator:id,nom_utilisateur',
                'updater:id,nom_utilisateur',
                'cashReceiptType:uuid,name,slug',
                'cashReceiptFamily:uuid,name',
                'method:uuid,name',
                'payment.order.salesCategory:uuid,name',
                'payment.order.items.menu:uuid,name',
                'payment.order.items.order.salesCategory:uuid,name',
                'payment.order.drinks.drinkConfig.product',
                'paymentLines' => fn($lineQuery) => $slug ? $lineQuery->where('slug', $slug) : null,
                'paymentLines.payable' => fn($morphTo) => $morphTo->morphWith([
                    \App\Models\OrderMenuRestaurantItem::class => ['menu:uuid,name,is_generated_from_complement', 'order.salesCategory:uuid,name'],
                    \App\Models\OrderRestaurantDrink::class => ['drinkConfig.product:uuid,name'],
                    \App\Models\RoomService::class => []
                ])
            ])
                ->where('type', 'encaissement')
                ->whereNotNull('cash_receipt_type_uuid')
                ->when($date, fn($q) => $q->whereDate('created_at', $date))
                ->whereNull('deleted_at');

            if ($slug) {
                $receiptsQuery->where(function ($q) use ($slug) {
                    $q->where('slug', 'like', '% ' . $slug)
                        ->orWhereHas('paymentLines', fn($lineQ) => $lineQ->where('slug', $slug));
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
                ->map(fn($items) => [
                    'receipt_type' => $items->first()->cashReceiptType,
                    'title'        => $receiptTitle,
                    'total_amount' => (float) $items->sum('amount'),
                    'items'        => $this->formatRegulationItems($items, $slug),
                ])
                ->values();
        }

        // 3. Recouvrements
        if ($shouldFetchRecouvrements) {
            $recouvrementsQuery = PaymentRegulation::with([
                'creator:id,nom_utilisateur',
                'updater:id,nom_utilisateur',
                'recouvrement:uuid,name,code,slug',
                'cashReceiptFamily:uuid,name',
                'method:uuid,name',
                'payment.order.salesCategory:uuid,name',
                'payment.order.items.menu:uuid,name',
                'payment.order.items.order.salesCategory:uuid,name',
                'payment.order.drinks.drinkConfig.product',
                'payment.order.partners_restaurant:uuid,full_name',
                'payment.order.free_client_for_restaurant:uuid,full_name',
                'paymentLines' => fn($lineQuery) => $slug ? $lineQuery->where('slug', $slug) : null,
                'paymentLines.payable' => fn($morphTo) => $morphTo->morphWith([
                    \App\Models\OrderMenuRestaurantItem::class => ['menu:uuid,name,is_generated_from_complement', 'order.salesCategory:uuid,name'],
                    \App\Models\OrderRestaurantDrink::class => ['drinkConfig.product:uuid,name'],
                    \App\Models\RoomService::class => []
                ])
            ])
                ->where('type', 'recouvrement')
                ->whereNotNull('recouvrement_uuid')
                ->when($date, fn($q) => $q->whereDate('created_at', $date))
                ->whereNull('deleted_at');

            if ($slug) {
                $recouvrementsQuery->where(function ($q) use ($slug) {
                    $q->where('slug', 'like', '% ' . $slug)
                        ->orWhereHas('paymentLines', fn($lineQ) => $lineQ->where('slug', $slug));
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
                    $clientType = null;

                    if ($order) {
                        if ($order->partners_restaurant) {
                            $clientName = $order->partners_restaurant->full_name;
                            $clientType = TypeClientsForPaiment::PARTNER->label();
                        } elseif ($order->free_client_for_restaurant) {
                            $clientName = $order->free_client_for_restaurant->full_name;
                            $clientType = TypeClientsForPaiment::FREE->label();
                        } elseif (!empty($order->full_name)) {
                            $clientName = $order->full_name;
                            $clientType = TypeClientsForPaiment::DEBTOR->label();
                        }
                    }

                    $regulation->resolved_client_name = $clientName;
                    $regulation->resolved_client_type = $clientType;
                    $regulation->order_total_amount = $slug ? (float) $regulation->paymentLines->sum('amount') : ($order ? (float) $order->total_order : 0);

                    return $regulation;
                })
                ->filter()
                ->groupBy('recouvrement_uuid')
                ->map(fn($items) => [
                    'recouvrement' => $items->first()->recouvrement,
                    'title'        => $recouvrementTitle,
                    'total_amount' => (float) $items->sum('amount'),

                    'clients' => $this->groupRecouvrementClients(
                        $items,
                        $slug
                    ),
                ])
                ->values();
        }

        // 4. Autres encaissements
        if ($shouldFetchOtherCashIns) {
            $otherCashInsQuery = \App\Models\OtherCashIn::with([
                'creator:id,nom_utilisateur',
                'updater:id,nom_utilisateur',
                'regulationMethod:uuid,name',
                'medias',
            ])
                ->where('status', 'validated')
                ->when($date, fn($q) => $q->whereDate('created_at', $date))
                ->whereNull('deleted_at');

            if ($slug) {
                if ($slug === 'AUTRES') {
                    $otherCashInsQuery->where(function ($q) {
                        $q->whereNull('slug')
                            ->orWhere('slug', '')
                            ->orWhere('slug', PdgCategory::AUTRES_ENCAISSEMENTS->value);
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

            $items = $otherCashInsQuery->orderByDesc('created_at')->get();

            $otherCashIns = $items->isNotEmpty() ? collect([[
                'cash_receipt_family' => null,
                'title'               => $otherCashInTitle,
                'total_amount'        => (float) $items->sum('amount'),
                'families'            => $this->buildOtherCashInTree($items),
            ]]) : collect([]);
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
        $formattedItems = $items->map(function ($regulation) use ($slug) {

            $order = $regulation->payment?->order;
            $orderCode = $order?->code;
            $method = $regulation->method;

            $lines = $regulation->paymentLines
                ? $regulation->paymentLines->whereNull('deleted_at')
                : collect();

            if ($slug) {
                $lines = $lines->where('slug', $slug);
            }

            $orderDetails = $this->extractOrderDetails(
                $lines,
                $orderCode,
                $method,
                $slug
            );

            $filteredTotal = $slug
                ? (float) $lines->sum('amount')
                : ($order ? (float) $order->total_order : 0);

            return [
                'uuid' => $regulation->uuid,
                'amount' => (float) $regulation->amount,
                'type' => $regulation->type,
                'created_at' => $regulation->created_at,
                'method' => $regulation->method,
                'cash_receipt_family' => $regulation->cashReceiptFamily,
                'recouvrement' => $regulation->recouvrement,
                'creator' => $regulation->creator,
                'order_code' => $orderCode,
                'order_total_price' => $filteredTotal,

                'order_details' => $orderDetails,
            ];
        })->values();
        return $this->regroupAllReceiptItems($formattedItems);
    }


    /**
     * Helper standard pour formater les règlements
     */
    private function formatRegulationItems($items, $slug = null)
    {
        $formattedItems = $items->map(function ($regulation) use ($slug) {

            $order = $regulation->payment?->order;
            $orderCode = $order?->code;
            $method = $regulation->method;

            $lines = $regulation->paymentLines
                ? $regulation->paymentLines->whereNull('deleted_at')
                : collect();

            if ($slug) {
                $lines = $lines->where('slug', $slug);
            }
            $orderDetails = $this->extractOrderDetails(
                $lines,
                $orderCode,
                $method,
                $slug
            );

            return [
                'uuid' => $regulation->uuid,
                'amount' => (float) $regulation->amount,
                'type' => $regulation->type,
                'created_at' => $regulation->created_at,
                'method' => $regulation->method,
                'cash_receipt_family' => $regulation->cashReceiptFamily,
                'recouvrement' => $regulation->recouvrement,
                'creator' => $regulation->creator,
                'order_code' => $orderCode,

                'order_total_price' => $order
                    ? (float) $order->total_order
                    : 0,

                'order_details' => $orderDetails,
            ];
        })->values();

        return $this->regroupAllReceiptItems($formattedItems);
    }

    private function regroupAllReceiptItems($formattedItems)
    {
        $allPlats = $formattedItems
            ->flatMap(function ($regulation) {

                return collect(
                    $regulation['order_details']['plats'] ?? []
                );
            })
            ->values();
        $groupedPlats = $allPlats
            ->groupBy(function ($rubric) {

                return strtoupper(
                    trim($rubric['rubric_name'] ?? 'AUTRES')
                );
            })
            ->map(function ($rubrics, $rubricName) {
                $allItems = $rubrics
                    ->flatMap(function ($rubric) {

                        return collect(
                            $rubric['items'] ?? []
                        );
                    })
                    ->values();
                $totalAmount = $allItems->sum(function ($item) {
                    return (float) ($item['total_price'] ?? 0);
                });

                return [
                    'rubric_name' => $rubricName,
                    'total_amount' => (float) $totalAmount,
                    'items' => $allItems,
                ];
            })
            ->values();

        $allBoissons = $formattedItems
            ->flatMap(function ($regulation) {

                return collect(
                    $regulation['order_details']['boissons'] ?? []
                );
            })
            ->values();

        return [
            'plats' => $groupedPlats,
            'boissons' => $allBoissons,
        ];
    }

    private function extractOrderDetails(
        $lines,
        $orderCode,
        $method,
        $slug = null
    ) {
        $formattedPlats = collect();

        if ($slug !== 'BAR') {
            $formattedPlats = $lines
                ->filter(function ($line) {
                    // On exclut les RoomService ici pour les traiter à part
                    if ($line->payable_type === \App\Models\RoomService::class || str_contains($line->payable_type, 'RoomService')) {
                        return false;
                    }
                    return $line->payable_type === \App\Models\OrderMenuRestaurantItem::class
                        || str_contains($line->payable_type, 'Item');
                })
                ->map(function ($line) use ($orderCode, $method) {
                    $item = $line->payable;
                    if (!$item) {
                        return null;
                    }
                    $item->order_code = $orderCode;
                    $item->payment_method = $method;
                    $item->is_room_service = false;

                    if (optional($item->menu)->is_generated_from_complement) {
                        $item->sales_category = RestaurantRubricEnum::DIVERS_RESTAURANT->value;
                    } else {
                        $rubricName = optional(optional($item->menu)->salesCategory)->name
                            ?? optional($item->salesCategory)->name
                            ?? optional(optional($item->order)?->salesCategory)->name
                            ?? 'AUTRES';
                        $item->sales_category = strtoupper(trim($rubricName));
                    }
                    return $item;
                })
                ->filter()
                ->values();
        }

        $rawBoissons = collect();

        if ($slug !== 'RESTO') {
            $rawBoissons = $lines
                ->filter(function ($line) use ($slug) {
                    if (
                        $line->payable_type === \App\Models\OrderRestaurantDrink::class
                        || str_contains($line->payable_type, 'Drink')
                    ) {
                        return true;
                    }
                    // Room service destiné au BAR
                    if (
                        ($line->payable_type === \App\Models\RoomService::class || str_contains($line->payable_type, 'RoomService'))
                        && (strtoupper($line->slug) === 'BAR' || $slug === 'BAR')
                    ) {
                        return true;
                    }
                    return false;
                })
                ->map(function ($line) use ($orderCode, $method) {
                    $item = $line->payable;
                    if (!$item) {
                        return null;
                    }
                    $item->order_code = $orderCode;
                    $item->payment_method = $method;

                    if ($line->payable_type === \App\Models\RoomService::class || str_contains($line->payable_type, 'RoomService')) {
                        $item->quantity_for_room_service = $line->quantity ?? 1;
                        $item->price_for_room_service = (float) $line->amount;
                        $item->is_room_service = true;
                    } else {
                        $item->is_room_service = false;
                    }
                    return $item;
                })
                ->filter()
                ->values();
        }

        if ($slug !== 'BAR') {
            // Extraction directe et sécurisée de TOUTES les lignes RoomService pour le RESTO ou globales
            $roomServicePlats = $lines
                ->filter(function ($line) use ($slug) {
                    return ($line->payable_type === \App\Models\RoomService::class || str_contains($line->payable_type, 'RoomService'))
                        && (strtoupper($line->slug) === 'RESTO' || empty($line->slug) || $slug === 'RESTO');
                })
                ->map(function ($line) use ($orderCode, $method) {
                    $item = $line->payable;
                    if (!$item) {
                        // Si l'objet lié n'est pas chargé via morph, on crée un objet virtuel pour ne pas perdre le montant de la ligne
                        $item = new \stdClass();
                    }

                    $item->order_code = $orderCode;
                    $item->payment_method = $method;
                    $item->quantity_for_room_service = $line->quantity ?? 1;
                    $item->price_for_room_service = (float) $line->amount;
                    $item->is_room_service = true;
                    $item->sales_category = 'ROOM SERVICE';
                    $item->libelle = 'Room Service';

                    return $item;
                })
                ->filter()
                ->values();

            $formattedPlats = $formattedPlats
                ->concat($roomServicePlats)
                ->values();
        }

        $groupedPlats = $formattedPlats
            ->groupBy(function ($item) {
                if (!empty($item->is_room_service)) {
                    return 'ROOM SERVICE';
                }
                return strtoupper(trim($item->sales_category ?? 'AUTRES'));
            })
            ->map(function ($platItems, $rubricName) {
                $items = $platItems
                    ->map(function ($item) {
                        $quantity = $item->quantity_exactly
                            ?? $item->quantity
                            ?? $item->quantity_for_room_service
                            ?? 1;

                        $unitPrice = $item->unit_price
                            ?? $item->price_for_room_service
                            ?? 0;

                        if (isset($item->total_price) && $item->total_price !== null) {
                            $totalPrice = (float) $item->total_price;
                        } elseif (isset($item->price_for_room_service) && $item->price_for_room_service !== null) {
                            $totalPrice = (float) $item->price_for_room_service;
                        } else {
                            $totalPrice = (float) $quantity * (float) $unitPrice;
                        }

                        if (!empty($item->is_room_service)) {
                            $libelle = 'Room Service';
                        } else {
                            $libelle = $item->libelle ?? optional($item->menu)->name ?? 'Article';
                        }

                        return [
                            'libelle' => $libelle,
                            'order_code' => $item->order_code ?? null,
                            'payment_method' => $item->payment_method ?? null,
                            'quantity_exactly' => $quantity,
                            'unit_price' => (float) $unitPrice,
                            'total_price' => $totalPrice,
                        ];
                    })
                    ->values();

                return [
                    'rubric_name' => $rubricName,
                    'total_amount' => (float) $items->sum(fn($item) => (float) $item['total_price']),
                    'items' => $items,
                ];
            })
            ->values();

        $formattedBoissons = $rawBoissons->map(function ($item) {
            $quantity = $item->quantity_exactly
                ?? $item->quantity
                ?? $item->quantity_for_room_service
                ?? 1;

            $unitPrice = $item->unit_price
                ?? $item->price_for_room_service
                ?? 0;

            if (isset($item->total_price) && $item->total_price !== null) {
                $totalPrice = (float) $item->total_price;
            } elseif (isset($item->price_for_room_service) && $item->price_for_room_service !== null) {
                $totalPrice = (float) $item->price_for_room_service;
            } else {
                $totalPrice = (float) $quantity * (float) $unitPrice;
            }

            if (!empty($item->is_room_service)) {
                $libelle = 'Room Service';
            } else {
                $libelle = $item->libelle
                    ?? optional(optional($item->drinkConfig)->product)->name
                    ?? $item->drink_name
                    ?? 'Boisson';
            }

            return [
                'libelle' => $libelle,
                'order_code' => $item->order_code ?? null,
                'payment_method' => $item->payment_method ?? null,
                'quantity_exactly' => $quantity,
                'unit_price' => (float) $unitPrice,
                'total_price' => $totalPrice,
            ];
        })->values();

        return [
            'plats' => $groupedPlats,
            'boissons' => $formattedBoissons,
        ];
    }
    private function groupRecouvrementClients($items, $slug = null)
    {
        return $items
            ->groupBy(function ($regulation) {
                $order = optional($regulation->payment)->order;

                if (!$order || $order->free_client_for_restaurant || (empty($order->full_name) && !$order->partners_restaurant)) {
                    return TypeClientsForPaiment::FREE->value;
                }

                if ($order->partners_restaurant) {
                    return TypeClientsForPaiment::PARTNER->value;
                }

                if (!empty($order->full_name)) {
                    return TypeClientsForPaiment::DEBTOR->value;
                }

                return TypeClientsForPaiment::FREE->value;
            })
            ->map(function ($clientRegulations, $clientTypeKey) use ($slug) {

                $enumType = TypeClientsForPaiment::tryFrom($clientTypeKey) ?? TypeClientsForPaiment::FREE;
                $clientTypeLabel = $enumType->label();

                $namesOrCodes = $clientRegulations
                    ->map(function ($regulation) use ($clientTypeKey) {
                        $order = optional($regulation->payment)->order;

                        if ($clientTypeKey === TypeClientsForPaiment::PARTNER->value) {
                            return optional($order->partners_restaurant)->full_name;
                        }
                        if ($clientTypeKey === TypeClientsForPaiment::DEBTOR->value) {
                            return $order->full_name ?? null;
                        }
                        return optional($order)->code;
                    })
                    ->filter()
                    ->unique()
                    ->values();

                $clientName = $namesOrCodes->isNotEmpty()
                    ? $namesOrCodes->implode(', ')
                    : $clientTypeLabel;

                $itemsFormatted = $this->formatRecouvrementClientItems(
                    $clientRegulations,
                    $slug
                );

                return [
                    'client_type' => $clientTypeLabel,
                    'client_name' => $clientName,
                    'items'       => $itemsFormatted,
                ];
            })
            ->values();
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


            if ($totalNewPaid > ($remainingToPay + 0.01)) {
                return response()->json([
                    'success' => false,
                    'message' => "Montant supérieur au reste à payer ({$remainingToPay})",
                ], 422);
            }


            $attachmentPath = null;
            if ($request->hasFile('image_file')) {
                $file = $request->file('image_file');
                $filename = time() . '_' . $file->getClientOriginalName();
                $attachmentPath = $file->store('regulations', 'public');

                if (isset($product)) {
                    $product->medias()->create([
                        'name' => $filename,
                        'disk' => 'public',
                        'path' => $attachmentPath,
                        'filename' => $filename,
                        'mimetype' => $file->getClientMimeType(),
                        'extension' => $file->getClientOriginalExtension(),
                    ]);
                }
            }

            foreach ($request->regulations as $regulation) {

                $method = RegulationMethod::where('uuid', $regulation['method_uuid'])->first();
                $cashReceiptType = CashReceiptType::where('is_linked_to_turnover', true)->first();
                $recouvrementRestoBar = Recouvrement::where('is_used_for_restaurant', true)->first();

                $itemsAmount = 0;
                $drinksAmount = 0;
                $roomServiceAmount = 0;

                $itemLines = [];
                $drinkLines = [];
                $roomServiceLines = [];

                if (!empty($regulation['lines'])) {
                    foreach ($regulation['lines'] as $line) {
                        if ($line['type'] === 'item') {
                            $itemsAmount += (float) $line['amount'];
                            $itemLines[] = $line;
                        } elseif ($line['type'] === 'drink') {
                            $drinksAmount += (float) $line['amount'];
                            $drinkLines[] = $line;
                        } elseif ($line['type'] === 'room_service') {
                            $roomServiceAmount += (float) $line['amount'];
                            $roomServiceLines[] = $line;
                        }
                    }
                } else {
                    $itemsAmount = (float) $regulation['amount'];
                }

                if ($roomServiceAmount > 0) {
                    $hasItems = $order->items()->count() > 0;
                    if ($hasItems) {
                        $itemsAmount += $roomServiceAmount;
                    } else {
                        $drinksAmount += $roomServiceAmount;
                    }
                }

                if ($itemsAmount > 0) {
                    $restoFamily = CashReceiptFamily::where('indexation', 'Consommation Restaurant')->first();

                    $regulationModelResto = PaymentRegulation::create([
                        'payment_uuid' => $payment->uuid,
                        'regulation_method_uuid' => $method->uuid,
                        'cash_receipt_families_uuid' => $restoFamily?->uuid,
                        'cash_receipt_type_uuid' => $cashReceiptType?->uuid,
                        'recouvrement_uuid' => $recouvrementRestoBar?->uuid,
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

                    if ($order->items()->count() > 0) {
                        foreach ($roomServiceLines as $line) {
                            PaymentLine::create([
                                'payment_uuid' => $payment->uuid,
                                'payment_regulation_uuid' => $regulationModelResto->uuid,
                                'payable_type' => RoomService::class,
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
                }

                if ($drinksAmount > 0) {
                    $barFamily = CashReceiptFamily::where('indexation', 'Consommation Bar')->first();

                    $regulationModelBar = PaymentRegulation::create([
                        'payment_uuid' => $payment->uuid,
                        'regulation_method_uuid' => $method->uuid,
                        'cash_receipt_families_uuid' => $barFamily?->uuid,
                        'cash_receipt_type_uuid' => $cashReceiptType?->uuid,
                        'recouvrement_uuid' => $recouvrementRestoBar?->uuid,
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

                    if ($order->items()->count() === 0) {
                        foreach ($roomServiceLines as $line) {
                            PaymentLine::create([
                                'payment_uuid' => $payment->uuid,
                                'payment_regulation_uuid' => $regulationModelBar->uuid,
                                'payable_type' => RoomService::class,
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
            'type' => 'required|in:item,drink,room_service,order,regulation',
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

            if ($request->type === 'room_service') {
                $lines = PaymentLine::where('payable_uuid', $request->target_uuid)
                    ->where('payable_type', \App\Models\RoomService::class)
                    ->where('payment_uuid', $payment->uuid)
                    ->get();

                if ($lines->isEmpty()) {
                    throw new \Exception("Aucun règlement trouvé pour ce room service");
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
