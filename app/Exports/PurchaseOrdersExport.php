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
     * Retourne la collection de données pour l'export Excel
     *
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
                'approver',
                'supplier',
                'transfered'
            ])->get();

            if ($orders->isEmpty()) {
                Log::warning('Export PurchaseOrders : aucune donnée à exporter');
                return collect([]);
            }

            return $orders->map(function ($order) {
                return [
                    'UUID' => $order->uuid,
                    'Fournisseur' => trim(($order->supplier->first_name ?? '') . ' ' . ($order->supplier->last_name ?? '')),
                    'Transférer à' => $order->transfered->nom_utilisateur ?? '',
                    'Transférer le' => optional($order->transfered_at)->format('d/m/Y H:i:s') ?? '',
                    'Cloturer le' => optional($order->closed_at)->format('d/m/Y H:i:s') ?? '',
                    'Motif de rejet' => $order->motif_rejet ?? '',
                    'Reference' => $order->reference ?? '',
                    'Type' => strtoupper($order->type ?? ''),
                    'Statut' => $order->status ?? '',
                    'Entrepôt d\'origine' => $order->warehouse_from->name ?? '',
                    'Entrepôt de destination' => $order->warehouseTo->name ?? '',
                    'Créé par' => $order->creator->nom_utilisateur ?? '',
                    'Modifié par' => $order->updater->nom_utilisateur ?? '',
                    'Approuvé par' => $order->approver->nom_utilisateur ?? '',
                    'Total Produits' => $order->items->count(),
                    'Produits' => $order->items
                        ->map(fn($item) => ($item->product->name ?? '---') . ' (Qté: ' . ($item->quantity ?? '---') . ')')
                        ->join(', '),
                    'Date de création' => optional($order->created_at)->format('d/m/Y H:i:s') ?? '',
                    'Date de modification' => optional($order->updated_at)->format('d/m/Y H:i:s') ?? '',
                ];
            });

        } catch (\Exception $e) {
            Log::error('Erreur export PurchaseOrdersExport : ' . $e->getMessage());
            return collect([]);
        }
    }

    /**
     * Titres des colonnes pour l'export Excel
     *
     * @return array
     */
    public function headings(): array
    {
        return [
            'UUID',
            'Fournisseur',
            'Transférer à',
            'Transférer le',
            'Cloturer le',
            'Motif de rejet',
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
