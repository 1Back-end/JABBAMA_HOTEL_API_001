<!doctype html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport"
          content="width=device-width, user-scalable=no, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <style>
        {!! $bootstrap !!}
    </style>

    <style>
        @import url('https://fonts.googleapis.com/css2?family=Merriweather:ital,opsz,wght@0,18..144,300..900;1,18..144,300..900&display=swap');
        body, html {
            height: 100%;
            margin: 0;
            padding: 0;
            font-size: 3mm !important;
            font-family: "Merriweather", serif;
        }

        .print-wrapper {
            position: relative;
        }

        .print-footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            height: 10mm;
            text-align: center;
        }

        .page-number:before {
            content: "Page " counter(page) " / " counter(pages);
        }

        h1 {
            font-size: 5mm !important;
        }

        table {
            page-break-inside: auto;
            width: 100%;
        }

        thead {
            display: table-header-group; /* Garde l'en-tête sur chaque page */
        }

        tfoot {
            display: table-footer-group;
        }

        tr {
            page-break-inside: avoid;
            page-break-after: auto;
        }

        img {
            width: auto;
            height: auto;
        }
    </style>
</head>

<body>

{{-- Header --}}
<header class="text-center">
    <div class="fs-3 fw-bold text-uppercase">
        BON D'APPROVISIONNEMENT N°{{ $supply->reference }}
    </div>
</header>

<div class="mt-2 w-100" style="border-top: 1px double rgba(0,0,0,0.75); margin-bottom: 2px"></div>
<div class="mb-2 w-100" style="border-top: 1px double rgba(0,0,0,0.75);"></div>

<div class="d-flex justify-content-between mb-3" style="font-size: 13px; gap: 2rem;">
    @if($supply->creator)
        <div>
            <div class="fs-6 fw-bold">Initier par :</div>
            <p class="m-0">
                <strong>Nom complet :</strong> <span
                    class="text-uppercase fw-bold fs-6">{{ $supply->creator?->nom_utilisateur ?? '—' }}</span>
            </p>
            <p class="m-0">
                <strong>Email :</strong> <span class="fw-bold fs-6">{{ $supply->creator?->email ?? '—' }}</span>
            </p>
        </div>
    @endif

    @if($supply->validator)
        <div>
            <div class="fs-6 fw-bold">Valider par:</div>
            <p class="m-0">
                <strong>Nom complet :</strong> <span
                    class="text-uppercase fw-bold fs-6">{{ $supply->validator?->nom_utilisateur ?? '—' }}</span>
            </p>
            <p class="m-0">
                <strong>Email :</strong> <span class="fw-bold fs-6">{{ $supply->validator?->email ?? '—' }}</span>
            </p>
        </div>
    @endif
</div>

<p class="fst-italic text-end">
    Date d'impression : {{ now()->format('d/m/Y H:i') }}
</p>

<div class="d-flex justify-content-center mt-2">
<table class="table table-bordered table-striped border-black" style="font-size: 11px;">
    <thead>
    <tr>
        <th colspan="12" class="text-center py-3" style="border-style: dotted">
            <strong>
               COMMANDE N° {{ $supply->purchaseOrder->reference }}
            </strong>
        </th>
    </tr>
    <tr>
        <th>N°</th>
        <th>Reference</th>
        <th>Article</th>
        <th>Qté commandée</th>
        <th>Qté approvisionnée</th>
        @if($supply->purchaseOrder->type === 'external')
            <th>Fournisseur(s)</th>
        @endif
        @if($supply->purchaseOrder->type === 'external')
            <th>Prix d'achat</th>
        @endif
    </tr>
    </thead>

    <tbody>
    @foreach($supply->items as $index => $item)
        <tr>
            <td>{{ $index + 1 }}</td>
            <td>{{ $item->product->code ?? '---' }}</td>
            <td>{{ $item->product->name ?? '---' }}</td>

            <td>
                {{ intval(optional($supply->purchaseOrder->items->firstWhere('product_uuid', $item->product_uuid))->quantity)
                    ?? '---'
                }}
            </td>

            <td>{{ intval($item->quantity_supplied ?? 0) ?: '---' }}</td>

            @if($supply->purchaseOrder->type === 'external')
                <td>{{ $item->supplier->company_name ?? '' }}</td>
            @endif

            @if($supply->purchaseOrder->type === 'external')
                <td>{{ \App\Helpers\FormatPrice::format(intval($item->purchase_price)) }}</td>
            @endif


        </tr>
    @endforeach
    </tbody>
</table>
</div>

<div class="d-flex align-content-center mb-3" style="font-size: 13px; gap: 2rem;">
    <p class="m-0">
        <strong>Type :</strong>
        <span class="text-capitalize">{{ \App\Enums\SupplyType::safeLabel($supply->type) }}</span>
    </p>
    <p class="m-0">
        <strong>Statut :</strong>
        <span class="text-capitalize">{{ \App\Enums\SupplyStatus::safeLabel($supply->status) }}</span>
    </p>
</div>




    <div class="text-end mt-3" style="font-size: 13px;">
        <p>Fait le {{ $supply->created_at->format('d/m/Y H:i') }}</p>
    </div>


</body>
</html>
