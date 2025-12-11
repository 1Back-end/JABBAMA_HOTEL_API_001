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
        $itemsList = $row->items->map(function($item) {
            $quantity = (int) $item->quantity;
            return $item->product?->name . ' (' . $quantity . ')';
        })->implode(', ');

        return [
            $row->reference,
            $row->type,
            $row->status,
            $row->notes || '',
            optional($row->creator)->nom_utilisateur,
            optional($row->updater)->nom_utilisateur,
            optional($row->approver)->nom_utilisateur,
            optional($row->approved_at)?->format('Y-m-d H:i:s'),
            optional($row->closed_at)?->format('Y-m-d H:i:s'),
            optional($row->transfered)->nom_utilisateur,
            optional($row->transfered_at)?->format('Y-m-d H:i:s'),
            $row->motif_rejet,
            $itemsList,

        ];
    }

    public function headings(): array
    {
        return [
            'Référence',
            'Type',
            'Statut',
            'Notes',
            'Créé Par',
            'Mis à jour Par',
            'Approuvé Par',
            'Approuvé Le',
            'Clôturé Le',
            'Transféré Par',
            'Transféré Le',
            'Motif Rejet',
            'Articles Commandés', // Nouvelle colonne
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
