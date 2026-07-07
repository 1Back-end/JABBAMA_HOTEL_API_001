<?php

namespace App\DTO;

use Illuminate\Http\Request;

class OrderMenuRestaurantFilterData
{
    public function __construct(
        public ?string $search = null,
        public ?string $status = null,
        public ?string $start_date = null,
        public ?string $end_date = null,
        public ?string $type_clients_for_payment = null,
        public ?string $consumption_type = null,
        public ?string $order_menu_restaurant_date = null,
        public ?string $restaurant_table_uuid = null,
        public ?string $menu_restaurant_uuid = null,
        public ?string $restaurant_room_uuid = null,
        public ?string $partners_restaurant_uuid = null,
        public int $limit = 25,
        public int $page = 1,
        public mixed $auth = null,
    ) {}

    public static function fromRequestPassationsFilterData(Request $request): self
    {
        return new self(
            search: $request->input('search'),
            status: $request->input('status'),
            start_date: $request->input('start_date'),
            end_date: $request->input('end_date'),
            type_clients_for_payment: $request->input('type_clients_for_payment'),
            consumption_type: $request->input('consumption_type'),
            order_menu_restaurant_date: $request->input('order_menu_restaurant_date'),
            restaurant_table_uuid: $request->input('restaurant_table_uuid'),
            menu_restaurant_uuid: $request->input('menu_restaurant_uuid'),
            restaurant_room_uuid: $request->input('restaurant_room_uuid'),
            partners_restaurant_uuid: $request->input('partners_restaurant_uuid'),
            limit: (int) $request->input('limit', 25),
            page: (int) $request->input('page', 1),
            auth: auth()->user()
        );
    }
}
