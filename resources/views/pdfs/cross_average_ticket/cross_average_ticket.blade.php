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
            display: table-header-group;
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

<header class="text-center mb-4">
    <div class="fs-3 fw-bold text-uppercase">
        TICKET MOYEN CROISÉ
    </div>

    @if(!empty($start_date) && !empty($end_date))
        <h3 style="font-style: italic; font-size: 11px; margin-top: 5px;">
            Période : {{ $start_date }} au {{ $end_date }}
        </h3>
    @endif
</header>

{{-- Ligne de séparation --}}
<div class="mt-2 w-100" style="border-top: 1px double rgba(0,0,0,0.75); margin-bottom: 2px"></div>
<div class="mb-2 w-100" style="border-top: 1px double rgba(0,0,0,0.75);"></div>

{{-- Date d'impression --}}
<p class="fst-italic text-end" style="font-size: 12px;">
    Date d'impression : {{ now()->format('d/m/Y H:i') }}
</p>

<div class="d-flex justify-content-center mt-2">
    <table class="table table-bordered table-striped border-black mb-4" style="font-size: 12px; width: 100%;">
        <thead>
        <tr class="text-center bg-light">
            <th colspan="3" class="text-uppercase fw-bold text-primary">TICKET MOYEN CROISÉ - MENUS</th>
        </tr>
        <tr>
            <th style="width: 45%;">LIBELLE</th>
            <th class="text-center" style="width: 25%;">TOTAL DES COMMANDES</th>
            <th class="text-end" style="width: 30%;">MONTANT</th>
        </tr>
        </thead>
        <tbody>
        @foreach($menu_statistics as $stat)
            <tr>
                <td class="fw-bold text-primary">TICKET MOYEN CROISÉ</td>
                <td class="text-center bg-warning-subtle">{{ $stat['cross_referenced_average_ticket']['total_orders'] }}</td>
                <td class="text-end">{{ \App\Helpers\FormatPrice::format($stat['cross_referenced_average_ticket']['value']) }}</td>
            </tr>
            <tr>
                <td class="fw-bold text-primary">PRIX MOYEN DE VENTES HÉBERGÉS</td>
                <td class="text-center bg-warning-subtle">{{ $stat['hosted_sales_average_price']['total_orders'] }}</td>
                <td class="text-end">{{ \App\Helpers\FormatPrice::format($stat['hosted_sales_average_price']['value']) }}</td>
            </tr>
            <tr>
                <td class="fw-bold text-primary">PRIX MOYEN NON HÉBERGÉS DIVERS</td>
                <td class="text-center bg-warning-subtle">{{ $stat['non_hosted_diverse_average_price']['total_orders'] }}</td>
                <td class="text-end">{{ \App\Helpers\FormatPrice::format($stat['non_hosted_diverse_average_price']['value']) }}</td>
            </tr>
            <tr>
                <td class="fw-bold text-primary">TICKET MOYEN CROISÉ PARTENAIRE</td>
                <td class="text-center bg-warning-subtle">{{ $stat['partner_average_ticket']['total_orders'] }}</td>
                <td class="text-end">{{ \App\Helpers\FormatPrice::format($stat['partner_average_ticket']['value']) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>

<div class="d-flex justify-content-center mt-2">
    <table class="table table-bordered table-striped border-black" style="font-size: 12px; width: 100%;">
        <thead>
        <tr class="text-center bg-light">
            <th colspan="3" class="text-uppercase fw-bold text-primary">TICKET MOYEN CROISÉ - BOISSONS</th>
        </tr>
        <tr>
            <th style="width: 45%;">LIBELLE</th>
            <th class="text-center" style="width: 25%;">TOTAL DES COMMANDES</th>
            <th class="text-end" style="width: 30%;">MONTANT</th>
        </tr>
        </thead>
        <tbody>
        @foreach($drink_statistics as $stat)
            <tr>
                <td class="fw-bold text-primary">TICKET MOYEN CROISÉ</td>
                <td class="text-center bg-warning-subtle">{{ $stat['cross_referenced_average_ticket']['total_orders'] }}</td>
                <td class="text-end">{{ \App\Helpers\FormatPrice::format($stat['cross_referenced_average_ticket']['value']) }}</td>
            </tr>
            <tr>
                <td class="fw-bold text-primary">PRIX MOYEN DE VENTES HÉBERGÉS</td>
                <td class="text-center bg-warning-subtle">{{ $stat['hosted_sales_average_price']['total_orders'] }}</td>
                <td class="text-end">{{ \App\Helpers\FormatPrice::format($stat['hosted_sales_average_price']['value']) }}</td>
            </tr>
            <tr>
                <td class="fw-bold text-primary">PRIX MOYEN NON HÉBERGÉS DIVERS</td>
                <td class="text-center bg-warning-subtle">{{ $stat['non_hosted_diverse_average_price']['total_orders'] }}</td>
                <td class="text-end">{{ \App\Helpers\FormatPrice::format($stat['non_hosted_diverse_average_price']['value']) }}</td>
            </tr>
            <tr>
                <td class="fw-bold text-primary">TICKET MOYEN CROISÉ PARTENAIRE</td>
                <td class="text-center bg-warning-subtle">{{ $stat['partner_average_ticket']['total_orders'] }}</td>
                <td class="text-end">{{ \App\Helpers\FormatPrice::format($stat['partner_average_ticket']['value']) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>

</body>
</html>
