<?php

namespace App\Http\Controllers;

use App\Enums\ConsumptionType;
use App\Enums\MenuOrderStatus;
use App\Enums\PurchaseOrdersStatus;
use App\Enums\RoomType;
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
    //
}
