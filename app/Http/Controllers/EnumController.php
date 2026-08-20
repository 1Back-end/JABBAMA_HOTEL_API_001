<?php

namespace App\Http\Controllers;

use App\Enums\CashRegisterFilterType;
use App\Enums\ChooseRubriquesSall;
use App\Enums\ConsumptionType;
use App\Enums\HistoricsEncaissementsOrRecouvrements;
use App\Enums\MenuOrderStatus;
use App\Enums\PaymentOrderMenusStatus;
use App\Enums\PurchaseOrdersStatus;
use App\Enums\RoomServiceEnum;
use App\Enums\RoomType;
use App\Enums\StatusRecouvrements;
use App\Enums\TypeClientsForPaiment;
use App\Enums\TypeClientsForPayment;
use Illuminate\Http\Request;

class EnumController extends Controller
{
    public function consumption_type_status()
    {
        return response()->json([
            'status' => 'success',
            'data'   => ConsumptionType::toArray(),
        ]);
    }

    public function type_clients_for_payment()
    {
        return response()->json([
            'status' => 'success',
            'data'   => TypeClientsForPaiment::toArray(),
        ]);
    }

    public function room_type()
    {
        return response()->json([
            'status' => 'success',
            'data'   => RoomType::toArray(),
        ]);
    }

    public function menu_orders_status()
    {
        return response()->json([
            'status' => 'success',
            'data'   => MenuOrderStatus::toArray(),
        ]);
    }

    public function ChooseRubriquesSall()
    {
        return response()->json([
            'status' => 'success',
            'data'   => ChooseRubriquesSall::toArray(),
        ]);
    }

    public function CashRegisterFilterType()
    {
        return response()->json([
            'status' => 'success',
            'data'   => CashRegisterFilterType::toArray(),
        ]);
    }

    public function PaymentOrderMenusStatus()
    {
        return response()->json([
            'status' => 'success',
            'data'   => PaymentOrderMenusStatus::toArray(),
        ]);
    }

    public function StatusRecouvrements()
    {
        return response()->json([
            'status' => 'success',
            'data'   => StatusRecouvrements::toArray(),
        ]);
    }

    public function HistoricsEncaissementsOrRecouvrements()
    {
        return response()->json([
            'status' => 'success',
            'data'   => HistoricsEncaissementsOrRecouvrements::toArray(),
        ]);
    }

    public function RoomServiceType()
    {
        return response()->json([
            'status' => 'success',
            'data'   => RoomServiceEnum::toArray(),
        ]);
    }



}
