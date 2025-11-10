<?php

namespace App\Exports;

use App\Models\PurchaseOrder;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class PurchaseOrdersExport implements FromCollection, WithHeadings
{
    /**
     * @return \Illuminate\Support\Collection
     */
    public function collection()
    {
        try {
            $orders = PurchaseOrder::with([
                'items.product',
                'warehouseTo.managers',
                'warehouse_from.managers',
                'creator',
                'updater',
                'approver'
            ])->get();

            if ($orders->isEmpty()) {
                // Si aucune commande, log et retourne collection vide
                Log::warning('Export PurchaseOrders : aucune donnée à exporter');
                return collect([]);
            }

            return $orders->map(function ($order) {
                return [
                    'UUID' => $order->uuid,
                    'Reference' => $order->reference,
                    'Type' => $order->type,
                    'Statut' => $order->status,
                    'Entrepôt d\'origine' => $order->warehouse_from->name ?? '',
                    'Entrepôt de destination' => $order->warehouseTo->name ?? '',
                    'Créé par' => $order->creator->nom_utilisateur ?? '',
                    'Modifié par' => $order->updater->nom_utilisateur ?? '',
                    'Approuvé par' => $order->approver->nom_utilisateur ?? '',
                    'Total Produits' => $order->items->count(),
                    'Produits' => $order->items->map(fn($item) => $item->product->name . ' (Qté: ' . $item->quantity . ')')->join(', '),
                    'Date de création' => optional($order->created_at)->format('d/m/Y H:i:s') ?? '',
                    'Date de modification' => optional($order->updated_at)->format('d/m/Y H:i:s') ?? '',
                ];
            });

        } catch (\Exception $e) {
            // Log l'erreur et retourne collection vide pour éviter de casser l'export
            Log::error('Erreur export PurchaseOrdersExport : ' . $e->getMessage());
            return collect([]);
        }
    }

    public function headings(): array
    {
        return [
            'UUID',
            'Reference',
            'Type',
            'Statut',
            'Entrepôt d\'origine',
            'Entrepôt de destination',
            'Créé par',
            'Modifié par',
            'Approuvé par',
            'Total Produits',
            'Produits',
            'Date de création',
            'Date de modification'
        ];
    }
}
