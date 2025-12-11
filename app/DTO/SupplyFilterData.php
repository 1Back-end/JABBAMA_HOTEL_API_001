<?php

namespace App\DTO;

use Illuminate\Http\Request;

class SupplyFilterData
{
    public function __construct(
        public ?string $search = null,
        public ?string $type = null,
        public ?string $status = null,
        public ?string $start_date = null,
        public ?string $end_date = null,
        public int $limit = 25,
        public int $page = 1,
        public mixed $auth = null,
    ) {}



    public static function fromRequestSupply(Request $request): self
    {
        return new self(
            search: $request->input('search'),
            type: $request->input('type'),
            status: $request->input('status'),
            start_date: $request->input('start_date'),
            end_date: $request->input('end_date'),
            limit: (int) $request->input('limit', 25),
            page: (int) $request->input('page', 1),
            auth: auth()->user()
        );
    }

}
