<?php

namespace App\Http\Controllers;

use App\Enums\MenuOrderStatus;
use App\Enums\PaymentStatus;
use App\Models\OrderMenuRestaurant;
use App\Models\Payment;
use App\Models\PaymentRegulation;
use App\Models\RegulationMethod;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{


    public function store(Request $request)
    {
        $request->validate([
            'order_menu_restaurant_uuid' => 'required|uuid',
            'total_amount' => 'required|numeric|min:0',
            'regulations' => 'required|array|min:1',
            'regulations.*.method_uuid' => 'required|uuid',
            'regulations.*.amount' => 'required|numeric|min:0.01',
        ]);

        DB::beginTransaction();

        try {

            $order = OrderMenuRestaurant::with([
                'free_client_for_restaurant',
                'partners_restaurant'
            ])->where('uuid', $request->order_menu_restaurant_uuid)
                ->firstOrFail();

            $payment = Payment::firstOrCreate(
                ['order_menu_restaurant_uuid' => $order->uuid],
                [
                    'total_amount' => $request->total_amount,
                    'paid_amount' => 0,
                    'remaining_amount' => $request->total_amount,
                    'status' => PaymentStatus::UNPAID->value,
                    'created_by' => auth()->id(),
                ]
            );

            $alreadyPaid = (float) $payment->paid_amount;
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
                    $errors["regulations.$index.method_uuid"][] = "Méthode invalide";
                    continue;
                }

                if ($method->comment_required && empty($regulation['reference'])) {
                    $errors["regulations.$index.reference"][] = "Référence obligatoire";
                }

                if ($method->phone_method && empty($regulation['phone_number'])) {
                    $errors["regulations.$index.phone_number"][] = "Numéro obligatoire";
                }

                if ($method->comment_required && empty($regulation['detail'])) {
                    $errors["regulations.$index.detail"][] = "Le commentaire est obligatoire";
                }

                if (!isset($regulation['amount']) || $regulation['amount'] <= 0) {
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

            $remainingToPay = $payment->total_amount - $alreadyPaid;

            if ($totalNewPaid > $remainingToPay) {
                return response()->json([
                    'success' => false,
                    'message' => "Montant supérieur au reste à payer ($remainingToPay)",
                ], 422);
            }

            foreach ($request->regulations as $regulation) {

                $method = RegulationMethod::where('uuid', $regulation['method_uuid'])->first();

                PaymentRegulation::create([
                    'payment_uuid' => $payment->uuid,
                    'regulation_method_uuid' => $method->uuid,
                    'amount' => (float) $regulation['amount'],
                    'phone_number' => $regulation['phone_number'] ?? null,
                    'reference' => $regulation['reference'] ?? null,
                    'detail' => $regulation['detail'] ?? null,
                    'created_by' => auth()->id(),
                ]);
            }

            $totalPaid = $alreadyPaid + $totalNewPaid;

            $payment->paid_amount = $totalPaid;
            $payment->remaining_amount = max(0, $payment->total_amount - $totalPaid);

            if ($totalPaid <= 0) {
                $payment->status = PaymentStatus::UNPAID->value;
                $order->status = MenuOrderStatus::FACTURATE->value;

            } elseif ($totalPaid < $payment->total_amount) {
                $payment->status = PaymentStatus::PARTIALLY_PAID->value;
                $order->status = MenuOrderStatus::PARTIALLY_PAID->value;

            } else {
                $payment->status = PaymentStatus::PAID->value;
                $order->status = MenuOrderStatus::PAID->value;
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


    public function update(Request $request, $uuid)
    {
        $request->validate([
            'method_uuid' => 'required|uuid',
            'amount' => 'required|numeric|min:0.01',
            'reason_for_cancel_or_update' => 'required|string',
        ]);

        DB::beginTransaction();

        try {

            $regulation = PaymentRegulation::where('uuid', $uuid)->firstOrFail();

            $payment = Payment::where('uuid', $regulation->payment_uuid)->firstOrFail();

            $order = OrderMenuRestaurant::with([
                'free_client_for_restaurant',
                'partners_restaurant'
            ])->where('uuid', $payment->order_menu_restaurant_uuid)->firstOrFail();

            $oldAmount = (float) $regulation->amount;
            $newAmount = (float) $request->amount;


            $method = RegulationMethod::where('uuid', $request->method_uuid)->firstOrFail();

            $regulation->update([
                'regulation_method_uuid' => $method->uuid,
                'amount' => $newAmount,
                'phone_number' => $request->phone_number ?? null,
                'reference' => $request->reference ?? null,
                'detail' => $request->detail ?? null,
                'reason_for_cancel_or_update' => $request->reason_for_cancel_or_update,
                'updated_by' => auth()->id(),
            ]);


            $paidAmount = PaymentRegulation::where('payment_uuid', $payment->uuid)->sum('amount');

            $payment->paid_amount = $paidAmount;
            $payment->remaining_amount = max(0, $payment->total_amount - $paidAmount);

            if ($paidAmount <= 0) {
                $payment->status = PaymentStatus::UNPAID->value;
                $order->status = MenuOrderStatus::FACTURATE->value;

            } elseif ($paidAmount < $payment->total_amount) {
                $payment->status = PaymentStatus::PARTIALLY_PAID->value;
                $order->status = MenuOrderStatus::PARTIALLY_PAID->value;

            } else {
                $payment->status = PaymentStatus::PAID->value;
                $order->status = MenuOrderStatus::PAID->value;
            }

            $payment->save();

            $difference = $newAmount - $oldAmount;

            if ($difference != 0) {

                $client = $order->free_client_for_restaurant
                    ?? $order->partners_restaurant;

                if ($client) {
                    if ($difference > 0) {
                        // on consomme plus d’arrhes
                        $client->decrement('amount_allocated', $difference);
                    } else {
                        // on restitue des arrhes
                        $client->increment('amount_allocated', abs($difference));
                    }
                } else {
                    if ($difference > 0) {
                        $order->decrement('amount_allocated', $difference);
                    } else {
                        $order->increment('amount_allocated', abs($difference));
                    }
                }
            }

            $order->updated_by = auth()->id();
            $order->save();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Encaissement mis à jour avec succès',
                'data' => $regulation
            ]);

        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }


    public function destroyRegulation(Request $request, $uuid)
    {
        $request->validate([
            'reason_for_cancel_or_update' => ['required', 'string']
        ]);

        DB::beginTransaction();

        try {

            $regulation = PaymentRegulation::with(['payment.order'])
                ->where('uuid', $uuid)
                ->firstOrFail();

            $payment = $regulation->payment;
            $order = $payment->order;

            $regulation->reason_for_cancel_or_update = $request->reason_for_cancel_or_update;
            $regulation->updated_by = auth()->id();
            $regulation->save();

            $regulation->delete();

            $paidAmount = PaymentRegulation::where('payment_uuid', $payment->uuid)->sum('amount');

            $payment->paid_amount = $paidAmount;
            $payment->remaining_amount = max(0, $payment->total_amount - $paidAmount);

            if ($paidAmount <= 0) {
                $payment->status = PaymentStatus::UNPAID->value;
                $order->status = MenuOrderStatus::FACTURATE->value;

            } elseif ($paidAmount < $payment->total_amount) {
                $payment->status = PaymentStatus::PARTIALLY_PAID->value;
                $order->status = MenuOrderStatus::PARTIALLY_PAID->value;

            } else {
                $payment->status = PaymentStatus::PAID->value;
                $order->status = MenuOrderStatus::PAID->value;
            }

            $payment->save();

            $order->load(['free_client_for_restaurant', 'partners_restaurant']);

            $client = $order->free_client_for_restaurant
                ?? $order->partners_restaurant;

            $restoredAmount = (float) $regulation->amount;

            if ($client) {
                $client->increment('amount_allocated', $restoredAmount);
            } else {
                $order->increment('amount_allocated', $restoredAmount);
            }

            $order->updated_by = auth()->id();
            $order->save();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Règlement supprimé avec succès'
            ]);

        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }

}
