<?php

namespace App\Exports;

use App\Models\Supply;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class SuppliesExport implements FromCollection, WithHeadings
{
    public function collection()
    {
        $supplies = Supply::with([
            'items.product',
            'purchaseOrder.items',
            'purchaseOrder.warehouseTo',
            'purchaseOrder.warehouse_from',
            'creator',
            'updater',
            'validator',
            'rejector',
            'partially_validated',
        ])->get();

        $data = [];

        foreach ($supplies as $supply) {
            foreach ($supply->items as $item) {

                $data[] = [
                    'Commande' => $supply->purchaseOrder->reference,

                    // 1. Reference approvisionnement
                    'Reference approvisionnement' => $supply->reference,

                    // 2. Type
                    'Type' => strtoupper($supply->purchaseOrder->type ?? '---'),

                    // 3. Date approvisionnement
                    'Date approvisionnement' => optional($supply->supply_date)->format('d/m/Y H:i:s'),

                    // 4. Produit
                    'Produit' => $item->product->name ?? '---',

                    // 5. Qté commandée
                    'Qté commandée' => optional(
                            $supply->purchaseOrder->items
                                ->firstWhere('product_uuid', $item->product_uuid)
                        )->quantity ?? '---',

                    // 6. Qté approvisionnée
                    'Qté approvisionnée' => $item->quantity_supplied ?? '---',

                    // 7. Prix d’achat
                    'Prix d\'achat' => $item->purchase_price ?? '---',

                    // 8. Note
                    'Note' => $item->note ?? '---',

                    // 10. Entrepôt source
                    'Entrepôt source' => $supply->purchaseOrder->type === 'internal'
                        ? ($supply->purchaseOrder->warehouse_from->name ?? '---')
                        : '---',

                    // 11. Entrepôt destination
                    'Entrepôt destination' => $supply->purchaseOrder->type === 'internal'
                        ? ($supply->purchaseOrder->warehouseTo->name ?? '---')
                        : '---',

                    // 12. Statut
                    'Statut' => $supply->status,

                    // 13. Raison rejet
                    'Raison rejet' => $supply->rejection_reason ?? '',

                    // 14. Raison validation partielle
                    'Raison validation partielle' => $supply->partial_validation_reason ?? '',

                    // 15. Créé par
                    'Créé par' => $supply->creator->nom_utilisateur ?? '---',

                    // 16. Mis à jour par
                    'Mis à jour par' => $supply->updater->nom_utilisateur ?? '---',

                    // 17. Valider par
                    'Valider par' => $supply->validator->nom_utilisateur ?? '---',

                    // 18. Rejet par
                    'Rejet par' => $supply->rejector->nom_utilisateur ?? '---',

                    // 19. Valider partiellement par
                    'Valider partiellement par' => $supply->partially_validated->nom_utilisateur ?? '---',
                ];
            }
        }

        return collect($data);
    }

    public function headings(): array
    {
        return [
            'Commande',
            'Reference approvisionnement',
            'Type',
            'Date approvisionnement',

            'Produit',
            'Qté commandée',
            'Qté approvisionnée',
            'Prix d\'achat',
            'Note',

            'Entrepôt source',
            'Entrepôt destination',

            'Statut',
            'Raison rejet',
            'Raison validation partielle',

            'Créé par',
            'Mis à jour par',
            'Valider par',
            'Rejet par',
            'Valider partiellement par',
        ];
    }
}
