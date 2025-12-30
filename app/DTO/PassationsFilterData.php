<?php

namespace App\DTO;

use Illuminate\Http\Request;

class PassationsFilterData
{
    public function __construct(
        public ?string $search = null,
        public ?string $agent_from_id = null,
        public ?string $status = null,
        public ?string $warehouse_uuid = null,
        public ?string $start_date = null,
        public ?string $end_date = null,
        public int $limit = 25,
        public int $page = 1,
        public mixed $auth = null,
    ) {}



    public static function fromRequestPassationsFilterData(Request $request): self
    {
        return new self(
            search: $request->input('search'),
            agent_from_id: $request->input('agent_from_id'),
            status: $request->input('status'),
            warehouse_uuid: $request->input('warehouse_uuid'),
            start_date: $request->input('start_date'),
            end_date: $request->input('end_date'),
            limit: (int) $request->input('limit', 25), // ✅ correspond à $limit
            page: (int) $request->input('page', 1),
            auth: auth()->user() // récupère l'utilisateur connecté
        );
    }

}
