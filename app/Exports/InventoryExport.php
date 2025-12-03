<?php

namespace App\Exports;

use App\Models\ProductPoint;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
class InventoryExport implements FromCollection,WithHeadings
{
    protected $warehouseUuid;

    public function __construct($warehouseUuid)
    {
        $this->warehouseUuid = $warehouseUuid;
    }


    public function collection()
    {
        // Récupère tous les articles pour l'entrepôt donné
        return ProductPoint::with('product', 'point')
            ->where('point_uuid', $this->warehouseUuid)
            ->get()
            ->map(function ($item) {
                return [
                    'Article' => $item->product->name,
                    'Code' => $item->product->code,
                    'Quantité' => $item->quantity,
                    'Entrepôt' => $item->point->name,
                    'Actif' => $item->is_active ? 'Oui' : 'Non',
                ];
            });
    }
    public function headings(): array
    {
        return [
            'Article',
            'Code',
            'Quantité',
            'Entrepôt',
            'Actif',
        ];
    }
}
