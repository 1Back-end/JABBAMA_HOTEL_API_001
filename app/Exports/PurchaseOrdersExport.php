<?php

namespace App\Exports;

use App\Models\PurchaseOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use LaravelIdea\Helper\App\Models\_IH_Client_QB;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class PurchaseOrdersExport  implements FromQuery, WithHeadings, WithMapping, WithColumnFormatting
{
    public function __construct(
        public Builder $orderQuery
    )
    {}

    public function query(): Relation|Builder|_IH_Client_QB|\Laravel\Scout\Builder|\Illuminate\Database\Query\Builder
    {
        return $this->orderQuery;
    }

    public function map($row): array
    {
        return [
            $row->type,
            $row->reference,
            optional($row->warehouse_from)->name,
            $row->is_parent ? 'Commande parent' : 'Commande enfant', // Correctement positionné
            $row->status,
            optional($row->creator)->nom_utilisateur,
            optional($row->updater)->nom_utilisateur,
            optional($row->created_at)?->format('Y-m-d H:i:s'),
            optional($row->updated_at)?->format('Y-m-d H:i:s'),
        ];
    }

    public function headings(): array
    {
        return [
            'Type',
            'Référence',
            'Entrepôt',                // Accent corrigé
            'Commande',                // Libellé correspondant à is_parent
            'Statut',
            'Créé Par',
            'Mis à jour Par',
            'Date Création',
            'Date Modification',
        ];
    }


    public function columnFormats(): array
    {
        return [
            "C" => NumberFormat::FORMAT_DATE_DDMMYYYY,
            "H" => NumberFormat::FORMAT_NUMBER,
            "J" => NumberFormat::FORMAT_NUMBER,
            "U" => NumberFormat::FORMAT_DATE_DATETIME
        ];
    }
}
