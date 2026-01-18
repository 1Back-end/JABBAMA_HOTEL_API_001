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
            font-weight: 400;
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

<header class="text-center">
    <div class="fs-3 fw-bold text-uppercase">
        RECAPITULATIF DU TABLEAU DE BORD

        @if(!empty($data['summary']['from']) && !empty($data['summary']['to']))
            <h3 style="font-style: italic; font-size: 11px;">
                Période : {{ $data['summary']['from'] }} → {{ $data['summary']['to'] }}
            </h3>
        @endif
    </div>
</header>

{{-- Ligne de séparation --}}
<div class="mt-2 w-100" style="border-top: 1px double rgba(0,0,0,0.75); margin-bottom: 2px;"></div>
<div class="mb-2 w-100" style="border-top: 1px double rgba(0,0,0,0.75);"></div>

{{-- Date d'impression --}}
<p class="fst-italic text-end" style="font-size: 12px;">
    Date d'impression : {{ now()->format('d/m/Y H:i') }}
</p>

{{-- ========================================= --}}
{{-- Stock Adjustments --}}
{{-- ========================================= --}}
<table class="table-striped table table-bordered border-dark" style="font-size: 11px;">
    <thead>
    <tr>
        <th colspan="12" class="text-center fw-bold py-2"
            style="border: 2px dotted black;">
            ÉTAT DES REGULARISATIONS DE STOCK
        </th>
    </tr>
    <tr>
        <th>Action</th>
        <th>Total</th>
    </tr>
    </thead>
    <tbody>
    @foreach($data['stock_adjustments'] as $adjustment)
        <tr>
            <td>{{ $adjustment['label'] }}</td>
            <td>{{ $adjustment['total'] }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

{{-- ========================================= --}}
{{-- Supplies --}}
{{-- ========================================= --}}

<table class="table-striped table table-bordered border-dark" style="font-size: 11px;">
    <thead>
    <tr>
        <th colspan="12" class="text-center fw-bold py-2"
            style="border: 2px dotted black;">
            ÉTAT DES APPROVISIONNEMENTS
        </th>
    </tr>
    <tr>
        <th>Statut</th>
        <th>Total</th>
    </tr>
    </thead>
    <tbody>
    @foreach($data['supplies'] as $supply)
        <tr>
            <td>{{ $supply['label'] }}</td>
            <td>{{ $supply['total'] }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

{{-- ========================================= --}}
{{-- Purchase Orders --}}
{{-- ========================================= --}}

<table class="table-striped table table-bordered border-dark" style="font-size: 11px;">
    <thead>
    <tr>
        <th colspan="12" class="text-center fw-bold py-2"
            style="border: 2px dotted black;">
            ÉTAT DES COMMANDES
        </th>
    </tr>
    <tr>
        <th>Statut</th>
        <th>Total</th>
    </tr>
    </thead>
    <tbody>
    @foreach($data['purchase_orders'] as $order)
        <tr>
            <td>{{ $order['label'] }}</td>
            <td>{{ $order['total'] }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

{{-- Footer --}}
<div class="text-end mt-4" style="font-size: 12px;">
    <p>Fait le {{ now()->format('d/m/Y H:i') }}</p>
</div>



</body>
</html>
