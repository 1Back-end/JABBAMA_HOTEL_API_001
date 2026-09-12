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
            font-size: 5px !important;
            font-family: "Merriweather", serif;
            color: #000;
            width: 100%;
            overflow-x: auto;
        }

        h3 {
            font-size: 11px !important;
            margin-bottom: 5px;
        }

        table {
            width: 100%;
            max-width: 100%;
            border-collapse: collapse;
            margin-bottom: 8px;
            page-break-inside: auto;
            table-layout: fixed;
        }
        .table-responsive {
            overflow-x: auto !important;
            -webkit-overflow-scrolling: touch;
            width: 100%;
        }

        th, td {
            border: 2px solid #084298 !important;
            padding: 3px !important;
            text-align: center;
            vertical-align: middle;
            overflow: hidden;
            word-wrap: break-word;
        }

        th {
            background-color: #f8f9fa !important;
            color: #084298 !important;
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

        .cell-category {
            background-color: #f8f9fa !important;
            color: #084298 !important;
        }

        .row-total {
            background-color: #e2e3e5 !important;
            color: #084298 !important;
        }

        .cell-danger {
            color: #dc3545 !important;
        }

        .col-categorie {
            width: 15%;
        }

        .col-type {
            width: 30%;
        }

        .col-qte {
            width: 2%;
        }

        .col-montant {
            width: 17%;
        }

        @page {
            size: A4;
            margin: 8mm 5mm 5mm 5mm;
            @top-center {
                content: "SUITE DE LA MAIN COURANTE DU RESTAURANT DU {{ \Carbon\Carbon::parse($date)->locale('fr')->isoFormat('D MMMM YYYY') }}";
                font-family: "Merriweather", serif;
                font-size: 11px;
                font-weight: bold;
                text-transform: uppercase;
            }
        }

        @page :first {
            @top-center {
                content: "";
            }
        }
    </style>
</head>

<body>

@php
    $cat_j = $count_by_category ?? [];
    $tot_j = $totals_by_category ?? [];
    $cat_p2 = $p2_count_by_category ?? $month_count_by_category ?? [];
    $tot_p2 = $p2_totals_by_category ?? $month_totals_by_category ?? [];

    // --- CALCULS RESTAURANT ---
    $rest_qty_j = ($cat_j['PETIT DEJEUNER'] ?? 0) + ($cat_j['DEJEUNER'] ?? 0) + ($cat_j['DINER'] ?? $cat_j['DINNER'] ?? 0) + ($total_quantity_divers ?? 0);
    $rest_amt_j = ($tot_j['PETIT DEJEUNER'] ?? 0) + ($tot_j['DEJEUNER'] ?? 0) + ($tot_j['DINER'] ?? $tot_j['DINNER'] ?? 0) + ($total_amount_divers ?? 0);

    $rest_qty_p2 = ($cat_p2['PETIT DEJEUNER'] ?? 0) + ($cat_p2['DEJEUNER'] ?? 0) + ($cat_p2['DINER'] ?? $cat_p2['DINNER'] ?? 0) + ($p2_total_quantity_divers ?? $month_total_quantity_divers ?? 0);
    $rest_amt_p2 = ($tot_p2['PETIT DEJEUNER'] ?? 0) + ($tot_p2['DEJEUNER'] ?? 0) + ($tot_p2['DINER'] ?? $tot_p2['DINNER'] ?? 0) + ($p2_total_amount_divers ?? $month_total_amount_divers ?? 0);


    $bar_qty_j = $total_drinks_quantity ?? 0;
    $bar_amt_j = $total_bar ?? 0;

    $bar_qty_p2 = $p2_total_drinks_quantity ?? $month_total_drinks_quantity ?? 0;
    $bar_amt_p2 = $p2_total_bar ?? $month_total_bar ?? 0;

    // --- CALCULS ROOM SERVICE ---
    $rs_qty_j = $total_quantity_room_service ?? 0;
    $rs_amt_j = $total_amount_room_service ?? 0;

    $rs_qty_p2 = $p2_total_quantity_room_service ?? $month_total_quantity_room_service ?? 0;
    $rs_amt_p2 = $p2_total_amount_room_service ?? $month_total_amount_room_service ?? 0;

    // --- TOTAUX GÉNÉRAUX ---
    $total_qty_j = $rest_qty_j + $bar_qty_j + $rs_qty_j;
    $total_amt_j = $rest_amt_j + $bar_amt_j + $rs_amt_j;

    $total_qty_p2 = $rest_qty_p2 + $bar_qty_p2 + $rs_qty_p2;
    $total_amt_p2 = $rest_amt_p2 + $bar_amt_p2 + $rs_amt_p2;
@endphp

<header class="text-center mb-3">
    <div class="fs-3 fw-bold text-uppercase">
        {{ $title }}
    </div>
</header>

<div class="mt-2 w-100" style="border-top: 1px double rgba(0,0,0,0.75); margin-bottom: 2px"></div>
<div class="mb-2 w-100" style="border-top: 1px double rgba(0,0,0,0.75);"></div>

<p class="fst-italic text-end">
    Date d'impression : {{ now()->format('d/m/Y H:i') }}
</p>
<div class="row px-2 mt-3">
    <div class="col-12 p-0">
        <div class="table-responsive">
            <table class="table table-bordered mb-0" style="font-size: 11px; color: #000000;">
                <thead style="font-size: 0.85rem;">
                <tr>
                    <th rowspan="2" class="align-middle py-1 col-categorie">
                        CATÉGORIE
                    </th>
                    <th rowspan="2" class="align-middle py-1 col-type">
                        TYPE
                    </th>
                    <th colspan="2" class="text-uppercase">
                        JOUR ({{ \Carbon\Carbon::parse($date)->locale('fr')->isoFormat('D MMMM YYYY') }})
                    </th>
                    <th colspan="2" class="text-uppercase">
                        DU {{ \Carbon\Carbon::parse($start_date)->locale('fr')->isoFormat('D MMMM YYYY') }} AU
                        {{ \Carbon\Carbon::parse($end_date)->locale('fr')->isoFormat('D MMMM YYYY') }}
                    </th>
                </tr>
                <tr>
                    <th class="py-1 col-qte">QTÉ</th>
                    <th class="py-1 col-montant">MONTANT</th>
                    <th class="py-1 col-qte">QTÉ</th>
                    <th class="py-1 col-montant">MONTANT</th>
                </tr>
                </thead>

                <tbody>
                <!-- Restaurant -->
                <tr>
                    <td rowspan="5" class="fw-bold text-uppercase align-middle cell-category py-1">
                        Restaurant
                    </td>
                    <td class="text-start ps-2 py-1">PETIT DÉJEUNER</td>
                    <td class="py-1 text-center">{{ $cat_j['PETIT DEJEUNER'] ?? 0 }}</td>
                    <td class="py-1 text-center">{{ \App\Helpers\FormatPrice::format($tot_j['PETIT DEJEUNER'] ?? 0) }}</td>
                    <td class="py-1 text-center">{{ $cat_p2['PETIT DEJEUNER'] ?? 0 }}</td>
                    <td class="py-1 text-center">{{ \App\Helpers\FormatPrice::format($tot_p2['PETIT DEJEUNER'] ?? 0) }}</td>
                </tr>

                <tr>
                    <td class="text-start ps-2 py-1">DÉJEUNER</td>
                    <td class="py-1 text-center">{{ $cat_j['DEJEUNER'] ?? 0 }}</td>
                    <td class="py-1 text-center">{{ \App\Helpers\FormatPrice::format($tot_j['DEJEUNER'] ?? 0) }}</td>
                    <td class="py-1 text-center">{{ $cat_p2['DEJEUNER'] ?? 0 }}</td>
                    <td class="py-1 text-center">{{ \App\Helpers\FormatPrice::format($tot_p2['DEJEUNER'] ?? 0) }}</td>
                </tr>

                <tr>
                    <td class="text-start ps-2 py-1">DINER</td>
                    <td class="py-1 text-center">{{ $cat_j['DINER'] ?? $cat_j['DINNER'] ?? 0 }}</td>
                    <td class="py-1 text-center">{{ \App\Helpers\FormatPrice::format($tot_j['DINER'] ?? $tot_j['DINNER'] ?? 0) }}</td>
                    <td class="py-1 text-center">{{ $cat_p2['DINER'] ?? $cat_p2['DINNER'] ?? 0 }}</td>
                    <td class="py-1 text-center">{{ \App\Helpers\FormatPrice::format($tot_p2['DINER'] ?? $tot_p2['DINNER'] ?? 0) }}</td>
                </tr>

                <tr>
                    <td class="text-start ps-2 py-1">DIVERS RESTAURANT</td>
                    <td class="py-1 text-center">{{ $total_quantity_divers ?? 0 }}</td>
                    <td class="py-1 text-center">{{ \App\Helpers\FormatPrice::format($total_amount_divers ?? 0) }}</td>
                    <td class="py-1 text-center">{{ $p2_total_quantity_divers ?? $month_total_quantity_divers ?? 0 }}</td>
                    <td class="py-1 text-center">{{ \App\Helpers\FormatPrice::format($p2_total_amount_divers ?? $month_total_amount_divers ?? 0) }}</td>
                </tr>

                <!-- Total Restaurant -->
                <tr class="fw-bold row-total">
                    <td class="text-start ps-2 py-1">TOTAL RESTAURANT</td>
                    <td class="py-1 text-center">{{ $rest_qty_j }}</td>
                    <td class="py-1 text-center">{{ \App\Helpers\FormatPrice::format($rest_amt_j) }}</td>
                    <td class="py-1 text-center">{{ $rest_qty_p2 }}</td>
                    <td class="py-1 text-center">{{ \App\Helpers\FormatPrice::format($rest_amt_p2) }}</td>
                </tr>

                <!-- Bar -->
                <tr>
                    <td rowspan="1" class="fw-bold text-uppercase align-middle cell-category py-1">Bar</td>
                    <td class="text-start ps-2 py-1">BOISSONS / BAR</td>
                    <td class="py-1 text-center">{{ $bar_qty_j }}</td>
                    <td class="py-1 text-center">{{ \App\Helpers\FormatPrice::format($bar_amt_j) }}</td>
                    <td class="py-1 text-center">{{ $bar_qty_p2 }}</td>
                    <td class="py-1 text-center">{{ \App\Helpers\FormatPrice::format($bar_amt_p2) }}</td>
                </tr>

                <!-- Room Service -->
                <tr>
                    <td class="fw-bold text-uppercase align-middle cell-category py-1">Room Service</td>
                    <td class="text-start ps-2 py-1">PRESTATIONS ROOM SERVICE</td>
                    <td class="py-1 text-center">{{ $rs_qty_j }}</td>
                    <td class="py-1 text-center">{{ \App\Helpers\FormatPrice::format($rs_amt_j) }}</td>
                    <td class="py-1 text-center">{{ $rs_qty_p2 }}</td>
                    <td class="py-1 text-center">{{ \App\Helpers\FormatPrice::format($rs_amt_p2) }}</td>
                </tr>

                <!-- Commandes non facturées -->
                <tr>
                    <td class="fw-bold text-uppercase align-middle cell-category py-1 cell-danger">COMMANDES NON FACTURÉES</td>
                    <td class="text-start ps-2 py-1 cell-danger">COMMANDES NON FACTURÉES</td>
                    <td class="py-1 text-center cell-danger">{{ $orders_not_traited_p1 ?? 0 }}</td>
                    <td class="py-1 text-center cell-danger">{{ \App\Helpers\FormatPrice::format($orders_not_traited_total_order_p1 ?? 0) }}</td>
                    <td class="py-1 text-center cell-danger">{{ $orders_not_traited_p2 ?? 0 }}</td>
                    <td class="py-1 text-center cell-danger">{{ \App\Helpers\FormatPrice::format($orders_not_traited_total_order_p2 ?? 0) }}</td>
                </tr>

                </tbody>

                <tfoot class="fw-bold">
                <!-- Totaux Généraux -->
                <tr class="row-total">
                    <td colspan="2" class="text-end py-1 pe-3">TOTAUX GÉNÉRAUX</td>
                    <td class="py-1 text-center">
                        {{ $total_qty_j }}
                    </td>
                    <td class="py-1 text-center">
                        {{ \App\Helpers\FormatPrice::format($total_amt_j) }}
                    </td>
                    <td class="py-1 text-center">
                        {{ $total_qty_p2 }}
                    </td>
                    <td class="py-1 text-center">
                        {{ \App\Helpers\FormatPrice::format($total_amt_p2) }}
                    </td>
                </tr>

                <!-- Encaissement -->
                <tr class="row-total">
                    <td colspan="2" class="text-end py-1 pe-3">ENCAISSEMENT</td>
                    <td colspan="2" class="py-1 text-center">
                        {{ \App\Helpers\FormatPrice::format($total_encaissement_p1 ?? $total_encaissement_jour ?? 0) }}
                    </td>
                    <td colspan="2" class="py-1 text-center">
                        {{ \App\Helpers\FormatPrice::format($total_encaissement_p2 ?? $total_encaissement_mois ?? 0) }}
                    </td>
                </tr>

                <!-- A Reporter -->
                <tr class="row-total">
                    <td colspan="2" class="text-end py-1 pe-3 cell-danger">A REPORTER</td>
                    <td colspan="2" class="py-1 text-center cell-danger">
                        {{ \App\Helpers\FormatPrice::format($report_amount_p1 ?? 0) }}
                    </td>
                    <td colspan="2" class="py-1 text-center cell-danger">
                        {{ \App\Helpers\FormatPrice::format($report_amount_p2 ?? 0) }}
                    </td>
                </tr>

                <!-- Recouvrement -->
                <tr class="row-total">
                    <td colspan="2" class="text-end py-1 pe-3">RECOUVREMENT</td>
                    <td colspan="2" class="py-1 text-center">
                        {{ \App\Helpers\FormatPrice::format($total_recouvrements_p1 ?? $total_recouvrements_jour ?? 0) }}
                    </td>
                    <td colspan="2" class="py-1 text-center">
                        {{ \App\Helpers\FormatPrice::format($total_recouvrements_p2 ?? $total_recouvrements_mois ?? 0) }}
                    </td>
                </tr>

                <!-- Total des débiteurs -->
                <tr class="row-total">
                    <td colspan="2" class="text-end py-1 pe-3 cell-danger">TOTAL DES DEBITEURS</td>
                    <td colspan="4" class="py-1 text-center cell-danger">
                        {{ \App\Helpers\FormatPrice::format($all_p2_total_amount_divers ?? 0) }}
                    </td>
                </tr>
                </tfoot>
            </table>
        </div>
    </div>
</div>

</body>
</html>
