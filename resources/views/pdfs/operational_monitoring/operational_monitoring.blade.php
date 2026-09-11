<!doctype html>
<html lang="fr">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, user-scalable=no, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">

    <style>
        {!! $bootstrap !!}
    </style>

    <style>
        @import url('https://fonts.googleapis.com/css2?family=Merriweather:ital,opsz,wght@0,18..144,300..900;1,18..144,300..900&display=swap');

        * {
            box-sizing: border-box;
        }

        body, html {
            height: 100%;
            margin: 0;
            padding: 5px;
            font-size: 8px !important;
            font-family: "Merriweather", serif;
            color: #000;
        }

        h3 {
            font-size: 11px !important;
            margin-bottom: 5px;
        }

        th {
            font-size: 7.5px !important;
            font-weight: bold;
        }

        thead {
            display: table-header-group;
        }

        tr {
            page-break-inside: avoid;
            page-break-after: auto;
        }

        .text-start {
            text-align: left !important;
        }

        table {
            width: 100%;
            table-layout: fixed;
        }

        .col-descriptif {
            width: 16%;
            text-align: center;
        }

        .col-montant {
            width: 21%;
            text-align: center;
        }

        .col-taux {
            width: 21%;
            text-align: center;
        }

        @page {
            size: A4;
            margin: 8mm 5mm 5mm 5mm;
            @top-center {
                content: "";
                font-family: "Merriweather", serif;
                font-size: 9px;
                font-weight: bold;
                text-transform: uppercase;
            }
        }
    </style>
</head>

<body>

<header class="text-center mb-3">
    <div class="fw-bold text-uppercase" style="font-size: 11px;">
        {{ $title }}
    </div>
</header>

<div class="mt-2 w-100" style="border-top: 1px double rgba(0,0,0,0.75); margin-bottom: 2px"></div>
<div class="mb-2 w-100" style="border-top: 1px double rgba(0,0,0,0.75);"></div>

<table class="table text-center mb-0 mt-3" style="border-collapse: collapse; border: 2px solid #084298;">
    <thead>
    <tr class="fw-bold" style="background-color: #f8f9fa;">
        <th rowspan="2" class="align-middle text-start ps-2 col-descriptif" style="border: 2px solid #084298; color: #000000 !important; background-color: #ffffff;">
            DESCRIPTIF
        </th>
        <th colspan="2" class="text-uppercase" style="background-color: #eaf4eb; color: #198754 !important; border: 2px solid #084298;">
            JOUR ({{ \Carbon\Carbon::parse($periode_1['date_debut'])->locale('fr')->isoFormat('D MMMM YYYY') }})
        </th>
        <th colspan="2" class="text-uppercase" style="background-color: #fef9e7; color: #b78103 !important; border: 2px solid #084298;">
            DU {{ \Carbon\Carbon::parse($periode_2['date_debut'])->locale('fr')->isoFormat('D MMMM YYYY') }} AU {{ \Carbon\Carbon::parse($periode_2['date_fin'])->locale('fr')->isoFormat('D MMMM YYYY') }}
        </th>
    </tr>
    <tr class="fw-bold" style="font-size: 8px;">
        <th class="col-montant" style="background-color: #eaf4eb; color: #198754 !important; border: 2px solid #084298;">MONTANT</th>
        <th class="col-taux" style="background-color: #eaf4eb; color: #198754 !important; border: 2px solid #084298;">TAUX</th>
        <th class="col-montant" style="background-color: #fef9e7; color: #b78103 !important; border: 2px solid #084298;">MONTANT</th>
        <th class="col-taux" style="background-color: #fef9e7; color: #b78103 !important; border: 2px solid #084298;">TAUX</th>
    </tr>
    </thead>
    <tbody>
    <!-- PRODUITS -->
    <tr>
        <td class="fw-bold text-center ps-2" style="background-color: #ffffff; border: 2px solid #084298; color: #000000 !important;">
            PRODUITS
        </td>
        <td colspan="2" style="background-color: #f4f9f4; color: #198754 !important; border: 2px solid #084298;" class="fw-semibold">
            {{ \App\Helpers\FormatPrice::format($total_produits_p1) }}
        </td>
        <td colspan="2" style="background-color: #fffdf5; color: #b78103 !important; border: 2px solid #084298;" class="fw-semibold">
            {{ \App\Helpers\FormatPrice::format($total_produits_p2) }}
        </td>
    </tr>
    <!-- DEPENSES -->
    <tr>
        <td class="fw-bold text-center ps-2" style="background-color: #ffffff; border: 2px solid #084298; color: #dc3545 !important;">
            DEPENSES
        </td>
        <td style="background-color: #fdf2f2; color: #dc3545 !important; border: 2px solid #084298;" class="fw-semibold">
            {{ \App\Helpers\FormatPrice::format($expenses_1) }}
        </td>
        <td style="background-color: #fdf2f2; color: #dc3545 !important; border: 2px solid #084298;" class="fw-semibold">
            {{ number_format($expense_rate_p1, 2, ',', ' ') }} %
        </td>
        <td style="background-color: #fdf2f2; color: #dc3545 !important; border: 2px solid #084298;" class="fw-semibold">
            {{ \App\Helpers\FormatPrice::format($expenses_2) }}
        </td>
        <td style="background-color: #fdf2f2; color: #dc3545 !important; border: 2px solid #084298;" class="fw-semibold">
            {{ number_format($expense_rate_p2, 2, ',', ' ') }} %
        </td>
    </tr>
    <!-- MARGES -->
    <tr>
        <td class="fw-bold text-center ps-2" style="background-color: #ffffff; border: 2px solid #084298; color: #000000 !important;">
            MARGES
        </td>
        <td style="background-color: #f4f9f4; border: 2px solid #084298;" class="fw-semibold {{ $margin_p1 >= 0 ? 'text-success' : 'text-danger' }}">
            {{ \App\Helpers\FormatPrice::format($margin_p1) }}
        </td>
        <td style="background-color: #f4f9f4; border: 2px solid #084298;" class="fw-semibold {{ $margin_rate_p1 >= 0 ? 'text-success' : 'text-danger' }}">
            {{ number_format($margin_rate_p1, 2, ',', ' ') }} %
        </td>
        <td style="background-color: #fffdf5; border: 2px solid #084298;" class="fw-semibold {{ $margin_p2 >= 0 ? 'text-success' : 'text-danger' }}">
            {{ \App\Helpers\FormatPrice::format($margin_p2) }}
        </td>
        <td style="background-color: #fffdf5; border: 2px solid #084298;" class="fw-semibold {{ $margin_rate_p2 >= 0 ? 'text-success' : 'text-danger' }}">
            {{ number_format($margin_rate_p2, 2, ',', ' ') }} %
        </td>
    </tr>
    </tbody>
</table>

<p class="fst-italic text-end m-0 mt-2" style="font-size: 7px;">
    Date d'impression : {{ now()->format('d/m/Y H:i') }}
</p>

</body>
</html>
