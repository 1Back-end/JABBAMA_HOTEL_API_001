<?php

namespace App\Exports;

use App\Models\ProductPoint;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class InventoryByPointExport implements FromCollection, WithHeadings, WithMapping
{
    protected string $pointUuid;

    public function __construct(string $pointUuid)
    {
        $this->pointUuid = $pointUuid;
    }

    /**
     * ✅ Données à exporter
     */
    public function collection()
    {
        return ProductPoint::with(['product', 'point'])
            ->where('point_uuid', $this->pointUuid)
            ->where('is_active', true)
            ->get();
    }

    /**
     * ✅ Colonnes Excel
     */
    public function headings(): array
    {
        return [
            'Référence',
            'Article',
            'Quantité en stock',
            'Statut',
            'Date création',
            'Dernière mise à jour',
            'Crée par',
            'Modifie par',
        ];
    }

    /**
     * ✅ Mapping ligne par ligne
     */
    public function map($row): array
    {
        return [
            $row->product->code ?? '<UNK>',
            $row->product->name ?? '<UNK>',
            $row->quantity,
            $row->is_active ? 'Actif' : 'Inactif',
            $row->created?->format('d/m/Y H:i:s'),
            $row->updated_at?->format('d/m/Y H:i:s'),
            $row->creator->nom_utilisateur ?? '<UNK>',
            $row->updater->nom_utilisateur ?? '<UNK>',

        ];
    }
}
