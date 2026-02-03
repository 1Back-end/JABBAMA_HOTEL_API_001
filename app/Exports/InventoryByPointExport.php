<?php

namespace App\Exports;

use App\Models\ProductPoint;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class InventoryByPointExport implements FromCollection, WithHeadings, WithMapping
{
    protected ?string $pointUuid;

    public function __construct(?string $pointUuid = null)
    {
        $this->pointUuid = ($pointUuid === 'all' || empty($pointUuid))
            ? null
            : $pointUuid;
    }

    /**
     * ✅ Données à exporter
     */
    public function collection()
    {
        // Cas 1 : Entrepôt précis
        if (!is_null($this->pointUuid)) {
            return ProductPoint::with([
                'product.unitMeasure',
                'product.category',
                'point',
                'creator',
                'updater'
            ])
                ->where('point_uuid', $this->pointUuid)
                ->get();
        }

        // Cas 2 : Tous les entrepôts → regrouper par produit
        return ProductPoint::select(
            'produit_uuid',
            DB::raw('SUM(quantity) as quantity'),
            DB::raw('MAX(stocks_minimal) as stocks_minimal'),
            DB::raw('MAX(created_at) as created_at'),
            DB::raw('MAX(updated_at) as updated_at'),
            DB::raw('MAX(created_by) as created_by'),
            DB::raw('MAX(updated_by) as updated_by')
        )
            ->with(['product.unitMeasure', 'product.category', 'creator', 'updater'])
            ->groupBy('produit_uuid')
            ->get()
            ->map(function($item) {
                // ⚠️ Forcer le chargement des relations
                $item->loadMissing(['product.unitMeasure', 'product.category', 'creator', 'updater']);
                return $item;
            });
    }

    /**
     * ✅ Colonnes Excel
     */
    public function headings(): array
    {
        return [
            'Référence',
            'Article',
            'Catégorie',
            'Unité',
            'Quantité',
            'Entrepôt',
            'Créé le',
            'Mis à jour le',
            'Créé par',
            'Modifié par'
        ];
    }

    /**
     * ✅ Mapping ligne par ligne
     */
    public function map($row): array
    {
        $categories = $row->product->category_json ?? [];
        $count = count($categories);

        if ($count >= 4) {
            // 3 premières + ... + dernière
            $displayCategories = array_merge(
                array_slice($categories, 0, 3),
                ['...'],
                [end($categories)]
            );
        } else {
            $displayCategories = $categories;
        }
        return [
            $row->product->code ?? '<UNK>',
            $row->product->name ?? '<UNK>',
            implode(' --> ', $displayCategories), // <-- ici
            $row->product->unitMeasure->name ?? '<UNK>',
            $row->quantity ?? 0,
            $row->point?->name ?? '<Tous>',
            optional($row->created_at)->format('d/m/Y H:i:s') ?? '<UNK>',
            optional($row->updated_at)->format('d/m/Y H:i:s') ?? '<UNK>',
            $row->creator->nom_utilisateur ?? '<UNK>',
            $row->updater->nom_utilisateur ?? '<UNK>',
        ];
    }


}
