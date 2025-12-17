<?php

namespace App\Exports;

use App\Models\Warehouse;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Illuminate\Support\Collection;

class WarehousesExportAll implements FromCollection, WithHeadings
{
    public function collection(): Collection
    {
        $warehouses = Warehouse::with([
            'creator',
            'updater',
            'natures',
            'managers'
        ])->orderBy('created_at', 'desc')->get();

        if ($warehouses->isEmpty()) {
            throw new \Exception('Aucune donnée à exporter');
        }

        return $warehouses->map(function ($warehouse) {
            return [
                $warehouse->ref,
                $warehouse->name,
                $warehouse->stock_type,
                $warehouse->address,
                $warehouse->total_stock ?? 0,
                $warehouse->is_active ? 'Actif' : 'Inactif',
                $warehouse->is_primary ? 'Entrepôt Principal' : 'Entrepôt Secondaire',
                optional($warehouse->creator)->nom_utilisateur,
                optional($warehouse->updater)->nom_utilisateur,
                $warehouse->managers->pluck('nom_utilisateur')->join(', '),
                $warehouse->natures->pluck('name')->join(', '),
                $warehouse->created_at?->format('Y-m-d H:i:s'),
            ];
        });
    }

    public function headings(): array
    {
        return [
            'Référence',
            'Nom de l\'entrepôt',
            'Type de stock',
            'Adresse',
            'Stock total',
            'Statut',
            'Type d\'entrepôt',
            'Créé par',
            'Mis à jour par',
            'Gestionnaires',
            'Natures',
            'Date de création',
        ];
    }
}
