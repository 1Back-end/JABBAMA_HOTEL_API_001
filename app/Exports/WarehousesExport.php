<?php

namespace App\Exports;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithHeadings;

class WarehousesExport implements FromQuery, WithHeadings, WithMapping
{
    public function __construct(
        public Builder $warehouseQuery
    ) {}

    /**
     * Requête pour l'export
     */
    public function query(): Relation|Builder|\Laravel\Scout\Builder|\Illuminate\Database\Query\Builder
    {
        return $this->warehouseQuery;
    }

    /**
     * Mapping des données exportées
     */
    public function map($row): array
    {
        return [
            $row->ref,
            $row->name,
            $row->stock_type,
            $row->address,
            $row->total_stock,
            $row->is_active ? 'Actif' : 'Inactif',
            $row->is_primary ? 'Entrepot Principal' : 'Entrepot Secondaire',
            optional($row->creator)->nom_utilisateur,
            optional($row->updater)->nom_utilisateur,

            $row->managers->pluck('nom_utilisateur')->join(', '),

            // Plusieurs natures → concaténation
            $row->natures->pluck('name')->join(', '),
        ];
    }

    /**
     * En-têtes du fichier Excel
     */
    public function headings(): array
    {
        return [
            'Référence',
            'Nom',
            'Type Stock',
            'Adresse',
            'Stock Total',
            'Statut',
            'Créé par',
            'Modifié par',
            'Gestionnaires',
            'Natures',
        ];
    }
}
