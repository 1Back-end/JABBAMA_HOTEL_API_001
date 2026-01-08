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
{{-- Header général --}}
<header class="text-center mb-3">
    <div class="fs-3 fw-bold text-uppercase">
        LISTE DES COMMANDES
    </div>
    <div class="fs-6">
        @if($start_date && $end_date)
            <h3 style="font-style: italic;font-size: 11px">
                Période : {{ $start_date }} au {{ $end_date }}
            </h3>
        @endif
    </div>
</header>

@foreach($orders as $order)
    {{-- Séparateurs --}}
    <div class="mt-2 w-100" style="border-top: 1px double rgba(0,0,0,0.75); margin-bottom: 2px;"></div>
    <div class="mb-2 w-100" style="border-top: 1px double rgba(0,0,0,0.75);"></div>

    <div class="d-flex justify-content-center mt-2 mb-3">
        <table class="table table-bordered border-black table-striped" style="font-size: 11px;">
            <thead>
            <tr>
                <th colspan="7"
                    class="text-center fw-bold py-2"
                    style="border: 2px dotted black;">
                    BON DE COMMANDE N° {{ $order->reference }} <br>
                    <span style="font-size: 10px; font-weight: normal;">
                        Articles commandés
                    </span>
                </th>
            </tr>
            <tr>
                <th>N°</th>
                <th>Réference</th>
                <th>Article</th>
                <th>Qté commandée</th>
            </tr>
            </thead>
            <tbody>
            @foreach($order->items as $index => $item)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td class="text-uppercase">{{ $item->product->code ?? '---' }}</td>
                    <td class="text-uppercase">{{ $item->product->name ?? '---' }}</td>
                    <td>{{ intval($item->quantity ?? 0) ?: '---' }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>

    {{-- Type et statut --}}
    <div class="d-flex align-content-center mb-1" style="font-size: 12px; gap: 2rem;">
        <p class="m-0"><strong>Type :</strong> {{ \App\Enums\PurchaseOrdersType::safeLabel($order->type) }}</p>
        <p class="m-0"><strong>Statut :</strong> {{ \App\Enums\PurchaseOrdersStatus::safeLabel($order->status) }}</p>
    </div>

@endforeach


<div class="text-end mt-3" style="font-size: 13px;">
    <p>Fait le {{ $order->created_at->format('d/m/Y H:i') }}</p>
</div>


</div>
</body>
</html>
