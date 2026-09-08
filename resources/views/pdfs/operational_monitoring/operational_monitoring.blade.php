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

        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 8px;
            page-break-inside: auto;
        }

        th, td {
            border: 1px solid #000 !important;
            padding: 4px !important;
            text-align: center;
            vertical-align: middle;
        }

        th {
            background-color: #f2f2f2 !important;
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

        @page {
            size: A5 landscape;
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

<div class="mt-1 w-100" style="border-top: 1px double rgba(0,0,0,0.75); margin-bottom: 4px"></div>

<table class="table table-bordered align-middle text-center mb-2" style="font-size: 9px;">
    <thead>
    <tr class="fw-bold" style="background-color: #f8f9fa">
        <th rowspan="2" class="align-middle text-start ps-2" style="width: 28%">
            DESCRIPTIF
        </th>
        <th colspan="2" class="text-uppercase" style="background-color: #eaf4eb; color: #198754; width: 36%;">
            JOUR ({{ \Carbon\Carbon::parse($periode_1['date_debut'])->locale('fr')->isoFormat('D MMMM YYYY') }})
        </th>
        <th colspan="2" class="text-uppercase" style="background-color: #fef9e7; color: #b78103; width: 36%;">
            DU {{ \Carbon\Carbon::parse($periode_2['date_debut'])->locale('fr')->isoFormat('D MMMM YYYY') }} AU {{ \Carbon\Carbon::parse($periode_2['date_fin'])->locale('fr')->isoFormat('D MMMM YYYY') }}
        </th>
    </tr>
    <tr class="fw-bold" style="font-size: 8px;">
        <th style="background-color: #eaf4eb; color: #198754">MONTANT</th>
        <th style="background-color: #eaf4eb; color: #198754">TAUX</th>
        <th style="background-color: #fef9e7; color: #b78103">MONTANT</th>
        <th style="background-color: #fef9e7; color: #b78103">TAUX</th>
    </tr>
    </thead>
    <tbody>
    <!-- PRODUITS -->
    <tr>
        <td class="fw-bold text-start ps-2" style="background-color: #f8f9fa">
            PRODUITS
        </td>
        <td colspan="2" style="background-color: #f4f9f4; color: #198754" class="fw-semibold">
            {{ number_format($total_produits_p1, 2, ',', ' ') }}
        </td>
        <td colspan="2" style="background-color: #fffdf5; color: #b78103" class="fw-semibold">
            {{ number_format($total_produits_p2, 2, ',', ' ') }}
        </td>
    </tr>
    <!-- DEPENSES -->
    <tr>
        <td class="fw-bold text-start ps-2" style="background-color: #f8f9fa; color: #dc3545">
            DEPENSES
        </td>
        <td style="background-color: #fdf2f2; color: #dc3545" class="fw-semibold">
            {{ number_format($expenses_1, 2, ',', ' ') }}
        </td>
        <td style="background-color: #fdf2f2; color: #dc3545" class="fw-semibold">
            {{ number_format($expense_rate_p1, 2, ',', ' ') }} %
        </td>
        <td style="background-color: #fdf2f2; color: #dc3545" class="fw-semibold">
            {{ number_format($expenses_2, 2, ',', ' ') }}
        </td>
        <td style="background-color: #fdf2f2; color: #dc3545" class="fw-semibold">
            {{ number_format($expense_rate_p2, 2, ',', ' ') }} %
        </td>
    </tr>
    <!-- MARGES -->
    <tr>
        <td class="fw-bold text-start ps-2" style="background-color: #f8f9fa">
            MARGES
        </td>
        <td style="background-color: #f4f9f4; color: #198754" class="fw-semibold">
            {{ number_format($margin_p1, 2, ',', ' ') }}
        </td>
        <td style="background-color: #f4f9f4; color: #198754" class="fw-semibold">
            {{ number_format($margin_rate_p1, 2, ',', ' ') }} %
        </td>
        <td style="background-color: #fffdf5; color: #b78103" class="fw-semibold">
            {{ number_format($margin_p2, 2, ',', ' ') }}
        </td>
        <td style="background-color: #fffdf5; color: #b78103" class="fw-semibold">
            {{ number_format($margin_rate_p2, 2, ',', ' ') }} %
        </td>
    </tr>
    </tbody>
</table>

<p class="fst-italic text-end m-0" style="font-size: 7px;">
    Date d'impression : {{ now()->format('d/m/Y H:i') }}
</p>

</body>
</html>
