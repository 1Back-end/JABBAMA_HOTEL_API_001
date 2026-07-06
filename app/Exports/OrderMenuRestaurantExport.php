<?php

namespace App\Exports;

use App\Enums\ConsumptionType;
use App\Enums\MenuOrderStatus;
use App\Enums\TypeClientsForPaiment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class OrderMenuRestaurantExport implements FromQuery, WithHeadings, WithMapping, WithColumnFormatting
{
    public function __construct(
        public Builder $orderQuery
    ) {}

    public function query(): Relation|Builder|\Illuminate\Database\Query\Builder
    {
        // On retourne la requête reçue sans exécuter ->get() ni ->paginate()
        return $this->orderQuery;
    }

    /**
     * @param \App\Models\OrderMenuRestaurant $row
     * @return array
     */
    public function map($row): array
    {
        $drinks = $row->drinks->map(function ($drink) {
            return optional($drink->drinkConfig?->product)->name;
        })->filter()->implode(', ');

        $menus = $row->items->map(function ($item) {
            return $item->menu?->name;
        })->filter()->implode(', ');

        $clientName = 'Standard';
        if ($row->type_clients_for_payment === \App\Enums\TypeClientsForPaiment::PARTNER->value) {
            $clientName = $row->partners_restaurant?->full_name ?? '';
        } elseif ($row->type_clients_for_payment === \App\Enums\TypeClientsForPaiment::FREE->value) {
            $clientName = $row->free_client_for_restaurant?->full_name ?? '';

        } else {
            $clientName = $row->full_name ?? '';
        }

        return [
            ConsumptionType::safeLabel($row->consumption_type),
            $row->code,
            $drinks ?: '',
            $menus ?: '',
            TypeClientsForPaiment::safeLabel($row->type_clients_for_payment),
            $clientName,
            optional($row->restaurantTable)->table_number ?? '',
            MenuOrderStatus::safeLabel($row->status),
            optional($row->creator)->nom_utilisateur ?? '',
            optional($row->updater)->nom_utilisateur ?? '',
            optional($row->created_at)?->format('Y-m-d H:i:s'),
            optional($row->updated_at)?->format('Y-m-d H:i:s'),
            $row->others_informations ?? ''

        ];
    }

    public function headings(): array
    {
        return [
            'Type commande',
            'Code',
            'Boissons',
            'Menu',
            'Type client',
            'Nom complet client',
            'Table',
            'Statut',
            'Créé Par',
            'Mis à jour Par',
            'Date Création',
            'Date Modification',
            'Commentaire',
        ];
    }

    public function columnFormats(): array
    {
        return [
            'K' => NumberFormat::FORMAT_DATE_DATETIME,
            'L' => NumberFormat::FORMAT_DATE_DATETIME
        ];
    }
}
