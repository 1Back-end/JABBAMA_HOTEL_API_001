<?php

namespace App\Exports;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class StockAdjustmentsExport implements FromQuery, WithHeadings, WithMapping
{
    public function __construct(
        public Builder $stocksAdjustmentQuery
    ) {}

    /**
     * Requête utilisée pour l'export
     */
    public function query(): Builder|Relation
    {
        return $this->stocksAdjustmentQuery;
    }

    /**
     * En-têtes Excel
     */
    public function headings(): array
    {
        return [
            'Référence',
            'Entrepôt',
            'Action',
            'Statut',
            'Créé le',
            'Modifié le',
            'Crée par',
            'Modifié par'
        ];
    }

    /**
     * Mapping des lignes
     */
    public function map($row): array
    {
        return [
            $row->reference,
            $row->warehouse?->name, // safe
            $row->action,
            $row->status,
            optional($row->created_at)->format('Y-m-d H:i:s'),
            optional($row->updated_at)->format('Y-m-d H:i:s'),
            optional($row->creator)->nom_utilisateur,
            optional($row->updater)->nom_utilisateur,
        ];
    }
}
