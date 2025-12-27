<?php

namespace App\Exports;

use App\Enums\SupplyStatus;
use App\Enums\SupplyType;
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

        return [
            SupplyType::safeLabel($row->type),
            $row->reference,
            $row->purchaseOrder->reference,
            optional($row->supply_date)?->format('Y-m-d H:i:s'),
            SupplyStatus::safeLabel($row->status), // ✅ traduction ici
            optional($row->creator)->nom_utilisateur,
            optional($row->updater)->nom_utilisateur,
            optional($row->created_at)?->format('Y-m-d H:i:s'),
            optional($row->updated_at)?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * En-têtes Excel
     */
    public function headings(): array
    {
        return [
            'Type',
            'Référence',
            'Commande',
            'Date Approvisionnement',
            'Statut',
            'Créé Par',
            'Mis à jour Par',
            'Date création',
            'Date modification',

        ];
    }

}
