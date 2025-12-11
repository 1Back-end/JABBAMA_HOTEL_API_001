<?php

namespace App\DTO;

use Illuminate\Http\Request;

class WarehouseFilterData
{
    public function __construct(
        public ?string $search = null,
        public int $limit = 25,
        public int $page = 1,
        public mixed $auth = null,
    ) {}



    public static function fromRequestWarehouse(Request $request): self
    {
        return new self(
            search: $request->input('search'),
            limit: (int) $request->input('limit', 25),
            page: (int) $request->input('page', 1),
            auth: auth()->user()
        );
    }

}
