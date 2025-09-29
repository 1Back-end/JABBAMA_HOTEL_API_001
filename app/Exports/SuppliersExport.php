<?php

namespace App\Exports;

use App\Models\Supplier;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class SuppliersExport implements FromCollection, WithHeadings
{
    public function collection()
    {
        $suppliers = Supplier::with(["creator","updater"])->latest()->get();
        Log::info($suppliers);

        if ($suppliers->isEmpty()) {
            throw new \Exception('Aucune donnée à exporter');
        }

        return $suppliers->map(function ($supplier) {
            return [
                'Uuid' => $supplier->uuid,
                'Reférence' => $supplier->ref,
                'Nom Complet' => $supplier->first_name . ' ' . $supplier->last_name,
                'Email' => $supplier->email || 'NA',
                'Téléphone Principal' => $supplier->phone_number,
                'Téléphone Secondaire' => $supplier->phone_number_2,
                'Numéro CNI' => $supplier->cni_number,
                'Adresse' => $supplier->address,
                'Nom Société' => $supplier->company_name,
                'Email société' => $supplier->company_email,
                'Téléphone société' => $supplier->company_phone,
                'Date de création' => $supplier->created_at?->format('d/m/Y H:i:s'),
                'Date de mise à jour' => $supplier->updated_at?->format('d/m/Y H:i:s'),
            ];
        });
    }

    public function headings(): array
    {
        return [
            'Uuid',
            'Reférence',
            'Nom Complet',
            'Email',
            'Téléphone Principal',
            'Téléphone Secondaire',
            'Numéro CNI',
            'Adresse',
            'Nom Société',
            'Email société',
            'Téléphone société',
            'Date de création',
            'Date de mise à jour',
        ];
    }
}
