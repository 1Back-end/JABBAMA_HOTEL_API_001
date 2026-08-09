<?php

use Illuminate\Support\Facades\Route;
Route::get('enums/consumption_type_status', [\App\Http\Controllers\EnumController::class, 'consumption_type_status']);
Route::get('enums/type_clients_for_payment', [\App\Http\Controllers\EnumController::class, 'type_clients_for_payment']);
Route::get('enums/room_type', [\App\Http\Controllers\EnumController::class, 'room_type']);
Route::get('enums/menu_orders_status', [\App\Http\Controllers\EnumController::class, 'menu_orders_status']);
Route::get('menus_orders_actions', [\App\Http\Controllers\MenuOrdersController::class, 'MenuOrderStatus']);
Route::get('enums/menus_complement_type', [\App\Http\Controllers\MenuOrdersController::class, 'MenuComplementType']);
Route::get('enums/rubriques_salle_types', [\App\Http\Controllers\EnumController::class, 'ChooseRubriquesSall']);
Route::get('enums/cash_register_filter_type', [\App\Http\Controllers\EnumController::class, 'CashRegisterFilterType']);
Route::get('enums/payment_orders_menu_status', [\App\Http\Controllers\EnumController::class, 'PaymentOrderMenusStatus']);


