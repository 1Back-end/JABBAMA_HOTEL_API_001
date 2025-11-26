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
        BON DE COMMANDE N° {{ $order->reference }}
    </div>
</header>

{{-- Séparateurs --}}
<div class="mt-2 w-100" style="border-top: 1px double rgba(0,0,0,0.75); margin-bottom: 2px;"></div>
<div class="mb-2 w-100" style="border-top: 1px double rgba(0,0,0,0.75);"></div>


@if(!empty($order->supplier))
    <div class="mb-3" style="font-size: 13px;">
        <p class="m-0">
            <strong>Fournisseur :</strong> <span class="text-uppercase fw-bold">{{ $order->supplier->company_name }}</span>
        </p>
        <p class="m-0">
            <strong>Adresse :</strong> <span class="text-uppercase">{{ $order->supplier->address }}</span>
        </p>
        <p class="m-0">
            <strong>Numéro Téléphone :</strong> <span class="text-uppercase">{{ $order->supplier->company_phone }}</span>
        </p>
        <p class="m-0">
            <strong>Email :</strong> <span class="text-uppercase">{{ $order->supplier->company_email }}</span>
        </p>
    </div>
@endif

{{-- Date d'impression --}}
<p class="fst-italic text-end" style="font-size: 13px;">
    Date d'impression : {{ now()->format('d/m/Y H:i') }}
</p>

{{-- Fournisseur --}}

@if(!empty($order->warehouse_from))
    <div class="mb-3" style="font-size: 13px;">
        <p class="m-0">
            <strong>Entrepôt source :</strong>
            <span class="text-uppercase fw-bold">{{ optional($order->warehouse_from)->name ?? '---' }}</span>
        </p>
        <p class="m-0">
            <strong>Type de stocks :</strong>
            <span class="text-uppercase">{{ optional($order->warehouse_from)->stock_type ?? '---' }}</span>
        </p>
        <p class="m-0">
            <strong>Adresse :</strong>
            <span class="text-uppercase">{{ optional($order->warehouse_from)->address ?? '---' }}</span>
        </p>
    </div>
@endif




<h1 class="fw-bold text-center text-uppercase mt-3" style="font-size: 16px;">
    Articles commandés
</h1>

<div class="d-flex justify-content-center mt-2">
    <table class="table table-bordered table-striped text-center border-black" style="font-size: 13px;">
        <thead>
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
                <td>{{ $item->product->code ?? '---' }}</td>
                <td>{{ $item->product->name ?? '---' }}</td>
                <td>{{ intval($item->quantity ?? 0) ?: '---' }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>

<div class="text-end mt-3" style="font-size: 13px;">
    <p>Fait le {{ $order->created_at->format('d/m/Y H:i') }}</p>
</div>


</div>
</body>
</html>
