<?php

namespace App\DTO;

use Illuminate\Http\Request;

class StockAdjustmentFilterData
{
    public function __construct(
        public ?string $warehouse_uuid = null,
        public ?int $action = null,
        public ?string $status = null,
        public ?string $start_date = null,
        public ?string $end_date = null,
        public int $limit = 25,
        public int $page = 1,
        public mixed $auth = null,
    ) {}



    public static function fromRequestStockAdjustmentFilterData(Request $request): self
    {
        return new self(
            warehouse_uuid: $request->input('$warehouse_uuid'),
            action: $request->input('action'),
            status: $request->input('status'),
            start_date: $request->input('start_date'),
            end_date: $request->input('end_date'),
            limit: (int) $request->input('limit', 25),
            page: (int) $request->input('page', 1),
            auth: auth()->user()
        );
    }

}
