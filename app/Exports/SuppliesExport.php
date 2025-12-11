<?php

namespace App\Exports;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class SuppliesExport implements FromQuery, WithHeadings, WithMapping
{
    public function __construct(
        public Builder $supplyQuery
    ) {}

    /**
     * Requête utilisée pour l'export
     */
    public function query(): Relation|Builder|_IH_Client_QB|\Laravel\Scout\Builder|\Illuminate\Database\Query\Builder
    {
        return $this->supplyQuery;
    }

    /**
     * Mapping des lignes Excel
     */
    public function map($row): array
    {
        $itemsList = $row->items->map(function($item) {
            $quantitySupplied = (int) $item->quantity_supplied; // quantité approvisionnée sans décimales
            $rest = (int) ($item->quantity_supplied - ($item->quantity_delivered ?? 0)); // reste
            return $item->product?->name
                . " (Approvisionné: $quantitySupplied, Reste: $rest)";
        })->implode(', ');
        return [
            $row->reference,
            $row->type,
            $row->status,
            $row->purchaseOrder->reference,
            $itemsList,
            optional($row->creator)->nom_utilisateur,
            optional($row->updater)->nom_utilisateur,
            optional($row->transferredBy)->nom_utilisateur,
            optional($row->validator)->nom_utilisateur,
            optional($row->cancelled)->nom_utilisateur,
            optional($row->rejector)->nom_utilisateur,
            optional($row->partially_validated)->nom_utilisateur,
            optional($row->created_at)?->format('Y-m-d H:i:s'),
            optional($row->updated_at)?->format('Y-m-d H:i:s'),
            optional($row->transferred_at)?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * En-têtes Excel
     */
    public function headings(): array
    {
        return [
            'Référence',
            'Type',
            'Statut',
            'Reférence',
            'Articles',
            'Crée par',
            'Modifié par',
            'Tranférér à',
            'Validé par',
            'Annulé par',
            'Rejeté par',
            'Validé partiellement par',
            'Date création',
            'Date modification',
            'Date Transfert'
        ];
    }

}
