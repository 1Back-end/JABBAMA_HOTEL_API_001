<?php

namespace App\DTO;

use Illuminate\Http\Request;

class ClientFilterData
{
    public function __construct(
        public ?string $search = null,
        public ?int $perPage = 25,
        public ?int $page = 1,
        public ?int $sexe = null,
        public ?int $status_familiale = null,
        public ?int $type_document = null,
        public ?string $type_cli = null,
        public ?int $status_cli = null,
        public ?string $sort_colonne = null,
        public ?string $sort_direction = null
    )
    {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            search: $request->input('search'),
            perPage: $request->input('perPage', 25),
            page: $request->input('page', 1),
            sexe: $request->input('sexe'),
            status_familiale: $request->input('status_familiale'),
            type_document: $request->input('type_document'),
            type_cli: $request->input('type_cli'),
            status_cli: $request->input('status_cli'),
            sort_colonne: $request->input('sort_colonne'),
            sort_direction: $request->input('sort_direction')
        );
    }

}
