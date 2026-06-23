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

            $order = OrderMenuRestaurant::where('uuid', $request->order_menu_restaurant_uuid)
                ->firstOrFail();

            $payment = Payment::firstOrCreate(
                [
                    'order_menu_restaurant_uuid' => $order->uuid,
                ],
                [
                    'total_amount' => $request->total_amount,
                    'paid_amount' => 0,
                    'remaining_amount' => $request->total_amount,
                    'status' => PaymentStatus::UNPAID->value,
                    'created_by' => auth()->id(),
                ]
            );

            // 🔥 IMPORTANT : montant déjà payé en base
            $alreadyPaid = (float) $payment->paid_amount;
            $totalNewPaid = 0;

            $errors = [];

            // =========================
            // VALIDATION + CALCUL
            // =========================
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

            // ❌ stop si erreurs
            if (!empty($errors)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Erreurs de validation',
                    'errors' => $errors
                ], 422);
            }

            // 🔥 sécurité : éviter dépassement facture
            $totalPaid = $alreadyPaid + $totalNewPaid;

            if ($totalPaid > $payment->total_amount) {
                return response()->json([
                    'success' => false,
                    'message' => 'Le montant dépasse le total de la facture',
                    'errors' => [
                        'regulations' => ['Montant supérieur au reste à payer']
                    ]
                ], 422);
            }

            // =========================
            // CREATE REGULATIONS
            // =========================
            foreach ($request->regulations as $regulation) {

                $method = RegulationMethod::where('uuid', $regulation['method_uuid'])->first();

                PaymentRegulation::create([
                    'payment_uuid' => $payment->uuid,
                    'regulation_method_uuid' => $method->uuid,
                    'amount' => $regulation['amount'],
                    'phone_number' => $regulation['phone_number'] ?? null,
                    'reference' => $regulation['reference'] ?? null,
                    'detail' => $regulation['detail'] ?? null,
                    'created_by' => auth()->id(),
                ]);
            }

            // =========================
            // UPDATE PAYMENT
            // =========================
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

            $order = OrderMenuRestaurant::where('uuid', $payment->order_menu_restaurant_uuid)->firstOrFail();

            $method = RegulationMethod::where('uuid', $request->method_uuid)->firstOrFail();

            $regulation->update([
                'regulation_method_uuid' => $method->uuid,
                'amount' => (float) $request->amount,
                'phone_number' => $request->phone_number,
                'reference' => $request->reference,
                'detail' => $request->detail,
                'reason_for_cancel_or_update' => $request->reason_for_cancel_or_update,
            ]);


            $payment->paid_amount = PaymentRegulation::where('payment_uuid', $payment->uuid)->sum('amount');

            $payment->remaining_amount = max(0, $payment->total_amount - $payment->paid_amount);

            if ($payment->paid_amount <= 0) {
                $payment->status = PaymentStatus::UNPAID->value;

            } elseif ($payment->paid_amount < $payment->total_amount) {
                $payment->status = PaymentStatus::PARTIALLY_PAID->value;

            } else {
                $payment->status = PaymentStatus::PAID->value;
            }

            $payment->save();

            if ($payment->paid_amount <= 0) {
                $order->status = MenuOrderStatus::FACTURATE->value;

            } elseif ($payment->paid_amount < $payment->total_amount) {
                $order->status = MenuOrderStatus::PARTIALLY_PAID->value;

            } else {
                $order->status = MenuOrderStatus::PAID->value;
            }

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


    public function destroyRegulation(Request $request, string $uuid)
    {
        $request->validate([
            'reason_for_cancel_or_update' => ['required', 'string']
        ]);

        DB::beginTransaction();

        try {

            $regulation = PaymentRegulation::with('payment.order')
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
