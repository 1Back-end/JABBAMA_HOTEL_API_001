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
            padding: 3px !important;
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
            size: A3 landscape;
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
            <table class="table table-bordered table-striped border-black" style="font-size: 11px;">
                <thead class="table-light text-primary fw-bold sticky-top" style="font-size: 0.85rem; z-index: 1">
                <tr>
                    <th rowspan="2" class="align-middle py-1 border-primary" style="width: 20%; border-width: 2px">
                        CATÉGORIE
                    </th>
                    <th rowspan="2" class="align-middle py-1 border-primary" style="width: 30%; border-width: 2px">
                        TYPE
                    </th>
                    <th colspan="2" class="border-primary text-uppercase border-bottom" style="border-width: 2px; width: 25%">
                        JOUR ({{ \Carbon\Carbon::parse($date)->locale('fr')->isoFormat('D MMMM YYYY') }})
                    </th>
                    <th colspan="2" class="border-primary text-uppercase border-bottom" style="border-width: 2px; width: 25%">
                        DU {{ \Carbon\Carbon::parse($start_date)->locale('fr')->isoFormat('D MMMM YYYY') }} AU
                        {{ \Carbon\Carbon::parse($end_date)->locale('fr')->isoFormat('D MMMM YYYY') }}
                    </th>
                </tr>
                <tr>
                    <th class="py-1 border-primary" style="width: 10%; border-width: 2px">QTÉ</th>
                    <th class="py-1 border-primary" style="width: 15%; border-width: 2px">MONTANT</th>
                    <th class="py-1 border-primary" style="width: 10%; border-width: 2px">QTÉ</th>
                    <th class="py-1 border-primary" style="width: 15%; border-width: 2px">MONTANT</th>
                </tr>
                </thead>

                <tbody>
                <!-- Restaurant -->
                <tr>
                    <td rowspan="5" class="fw-bold text-uppercase align-middle bg-light text-secondary py-1 border-primary" style="border-width: 2px">
                        Restaurant
                    </td>
                    <td class="text-start ps-2 py-1 border-primary" style="border-width: 2px">PETIT DÉJEUNER</td>
                    <td class="py-1 border-primary text-center" style="border-width: 2px">{{ $count_by_category['PETIT DEJEUNER'] ?? 0 }}</td>
                    <td class="py-1 border-primary text-center" style="border-width: 2px">{{ \App\Helpers\FormatPrice::format($totals_by_category['PETIT DEJEUNER'] ?? 0) }}</td>
                    <td class="py-1 border-primary text-center" style="border-width: 2px">{{ $p2_count_by_category['PETIT DEJEUNER'] ?? 0 }}</td>
                    <td class="py-1 border-primary text-center" style="border-width: 2px">{{ \App\Helpers\FormatPrice::format($p2_totals_by_category['PETIT DEJEUNER'] ?? 0) }}</td>
                </tr>

                <tr>
                    <td class="text-start ps-2 py-1 border-primary" style="border-width: 2px">DÉJEUNER</td>
                    <td class="py-1 border-primary text-center" style="border-width: 2px">{{ $count_by_category['DEJEUNER'] ?? 0 }}</td>
                    <td class="py-1 border-primary text-center" style="border-width: 2px">{{ \App\Helpers\FormatPrice::format($totals_by_category['DEJEUNER'] ?? 0) }}</td>
                    <td class="py-1 border-primary text-center" style="border-width: 2px">{{ $p2_count_by_category['DEJEUNER'] ?? 0 }}</td>
                    <td class="py-1 border-primary text-center" style="border-width: 2px">{{ \App\Helpers\FormatPrice::format($p2_totals_by_category['DEJEUNER'] ?? 0) }}</td>
                </tr>

                <tr>
                    <td class="text-start ps-2 py-1 border-primary" style="border-width: 2px">DINER</td>
                    <td class="py-1 border-primary text-center" style="border-width: 2px">{{ $count_by_category['DINER'] ?? $count_by_category['DINNER'] ?? 0 }}</td>
                    <td class="py-1 border-primary text-center" style="border-width: 2px">{{ \App\Helpers\FormatPrice::format($totals_by_category['DINER'] ?? $totals_by_category['DINNER'] ?? 0) }}</td>
                    <td class="py-1 border-primary text-center" style="border-width: 2px">{{ $p2_count_by_category['DINER'] ?? $p2_count_by_category['DINNER'] ?? 0 }}</td>
                    <td class="py-1 border-primary text-center" style="border-width: 2px">{{ \App\Helpers\FormatPrice::format($p2_totals_by_category['DINER'] ?? $p2_totals_by_category['DINNER'] ?? 0) }}</td>
                </tr>

                <tr>
                    <td class="text-start ps-2 py-1 border-primary" style="border-width: 2px">DIVERS RESTAURANT</td>
                    <td class="py-1 border-primary text-center" style="border-width: 2px">{{ $total_quantity_divers ?? 0 }}</td>
                    <td class="py-1 border-primary text-center" style="border-width: 2px">{{ \App\Helpers\FormatPrice::format($total_amount_divers ?? 0) }}</td>
                    <td class="py-1 border-primary text-center" style="border-width: 2px">{{ $p2_total_quantity_divers ?? 0 }}</td>
                    <td class="py-1 border-primary text-center" style="border-width: 2px">{{ \App\Helpers\FormatPrice::format($p2_total_amount_divers ?? 0) }}</td>
                </tr>

                <!-- Total Restaurant -->
                <tr class="fw-bold table-active">
                    <td class="text-start ps-2 text-primary py-1 border-primary" style="border-width: 2px">TOTAL RESTAURANT</td>
                    <td class="py-1 border-primary text-center" style="border-width: 2px">{{ $totalQtyJour ?? 0 }}</td>
                    <td class="py-1 border-primary text-center" style="border-width: 2px">{{ \App\Helpers\FormatPrice::format($totalAmtJour ?? 0) }}</td>
                    <td class="py-1 border-primary text-center" style="border-width: 2px">{{ $p2TotalQty ?? 0 }}</td>
                    <td class="py-1 border-primary text-center" style="border-width: 2px">{{ \App\Helpers\FormatPrice::format($p2TotalAmt ?? 0) }}</td>
                </tr>

                <!-- Bar -->
                <tr>
                    <td rowspan="2" class="fw-bold text-uppercase align-middle bg-light text-secondary py-1 border-primary" style="border-width: 2px">Bar</td>
                    <td class="text-start ps-2 py-1 border-primary" style="border-width: 2px">BOISSONS / BAR</td>
                    <td class="py-1 border-primary text-center" style="border-width: 2px">{{ $total_drinks_quantity ?? 0 }}</td>
                    <td class="py-1 border-primary text-center" style="border-width: 2px">{{ \App\Helpers\FormatPrice::format($total_bar ?? 0) }}</td>
                    <td class="py-1 border-primary text-center" style="border-width: 2px">{{ $p2_total_drinks_quantity ?? 0 }}</td>
                    <td class="py-1 border-primary text-center" style="border-width: 2px">{{ \App\Helpers\FormatPrice::format($p2_total_bar ?? 0) }}</td>
                </tr>

                <!-- Total Bar -->
                <tr class="fw-bold table-active">
                    <td class="text-start ps-2 text-primary py-1 border-primary" style="border-width: 2px">TOTAL BAR</td>
                    <td class="py-1 border-primary text-center" style="border-width: 2px">{{ $total_drinks_quantity ?? 0 }}</td>
                    <td class="py-1 border-primary text-center" style="border-width: 2px">{{ \App\Helpers\FormatPrice::format($total_bar ?? 0) }}</td>
                    <td class="py-1 border-primary text-center" style="border-width: 2px">{{ $p2_total_drinks_quantity ?? 0 }}</td>
                    <td class="py-1 border-primary text-center" style="border-width: 2px">{{ \App\Helpers\FormatPrice::format($p2_total_bar ?? 0) }}</td>
                </tr>

                <!-- Room Service -->
                <tr>
                    <td rowspan="2" class="fw-bold text-uppercase align-middle bg-light text-secondary py-1 border-primary" style="border-width: 2px">Room Service</td>
                    <td class="text-start ps-2 py-1 border-primary" style="border-width: 2px">PRESTATIONS ROOM SERVICE</td>
                    <td class="py-1 border-primary text-center" style="border-width: 2px">{{ $total_quantity_room_service ?? 0 }}</td>
                    <td class="py-1 border-primary text-center" style="border-width: 2px">{{ \App\Helpers\FormatPrice::format($total_amount_room_service ?? 0) }}</td>
                    <td class="py-1 border-primary text-center" style="border-width: 2px">{{ $p2_total_quantity_room_service ?? 0 }}</td>
                    <td class="py-1 border-primary text-center" style="border-width: 2px">{{ \App\Helpers\FormatPrice::format($p2_total_amount_room_service ?? 0) }}</td>
                </tr>

                <!-- Total Room Service -->
                <tr class="fw-bold table-active">
                    <td class="text-start ps-2 text-primary py-1 border-primary" style="border-width: 2px">TOTAL ROOM SERVICE</td>
                    <td class="py-1 border-primary text-center" style="border-width: 2px">{{ $total_quantity_room_service ?? 0 }}</td>
                    <td class="py-1 border-primary text-center" style="border-width: 2px">{{ \App\Helpers\FormatPrice::format($total_amount_room_service ?? 0) }}</td>
                    <td class="py-1 border-primary text-center" style="border-width: 2px">{{ $p2_total_quantity_room_service ?? 0 }}</td>
                    <td class="py-1 border-primary text-center" style="border-width: 2px">{{ \App\Helpers\FormatPrice::format($p2_total_amount_room_service ?? 0) }}</td>
                </tr>
                </tbody>

                <tfoot class="fw-bold">
                <!-- Totaux Généraux -->
                <tr class="table-secondary">
                    <td colspan="2" class="text-end py-1 pe-3 border-primary" style="border-width: 2px">TOTAUX GÉNÉRAUX</td>
                    <td class="py-1 border-primary text-center" style="border-width: 2px">
                        {{ ($totalQtyJour ?? 0) + ($total_drinks_quantity ?? 0) + ($total_quantity_room_service ?? 0) }}
                    </td>
                    <td class="py-1 border-primary text-center" style="border-width: 2px">
                        {{ \App\Helpers\FormatPrice::format(($totalAmtJour ?? 0) + ($total_bar ?? 0) + ($total_amount_room_service ?? 0)) }}
                    </td>
                    <td class="py-1 border-primary text-center" style="border-width: 2px">
                        {{ ($p2TotalQty ?? 0) + ($p2_total_drinks_quantity ?? 0) + ($p2_total_quantity_room_service ?? 0) }}
                    </td>
                    <td class="py-1 border-primary text-center" style="border-width: 2px">
                        {{ \App\Helpers\FormatPrice::format(($p2TotalAmt ?? 0) + ($p2_total_bar ?? 0) + ($p2_total_amount_room_service ?? 0)) }}
                    </td>
                </tr>

                <!-- Encaissement -->
                <tr>
                    <td colspan="2" class="text-end py-1 pe-3 border-primary" style="border-width: 2px">ENCAISSEMENT</td>
                    <td colspan="2" class="py-1 border-primary text-center" style="border-width: 2px">
                        {{ \App\Helpers\FormatPrice::format($total_encaissement_p1 ?? $total_encaissement_jour ?? 0) }}
                    </td>
                    <td colspan="2" class="py-1 border-primary text-center" style="border-width: 2px">
                        {{ \App\Helpers\FormatPrice::format($total_encaissement_p2 ?? 0) }}
                    </td>
                </tr>

                <!-- A Reporter -->
                <tr>
                    <td colspan="2" class="text-end py-1 pe-3 border-primary" style="border-width: 2px">A REPORTER</td>
                    <td colspan="2" class="py-1 border-primary text-center" style="border-width: 2px">
                        {{ \App\Helpers\FormatPrice::format($report_amount_p1 ?? 0) }}
                    </td>
                    <td colspan="2" class="py-1 border-primary text-center" style="border-width: 2px">
                        {{ \App\Helpers\FormatPrice::format($report_amount_p2 ?? 0) }}
                    </td>
                </tr>

                <!-- Recouvrement -->
                <tr>
                    <td colspan="2" class="text-end py-1 pe-3 border-primary" style="border-width: 2px">RECOUVREMENT</td>
                    <td colspan="2" class="py-1 border-primary text-center" style="border-width: 2px">
                        {{ \App\Helpers\FormatPrice::format($total_recouvrements_p1 ?? $total_recouvrements_jour ?? 0) }}
                    </td>
                    <td colspan="2" class="py-1 border-primary text-center" style="border-width: 2px">
                        {{ \App\Helpers\FormatPrice::format($total_recouvrements_p2 ?? 0) }}
                    </td>
                </tr>

                <!-- Total des débiteurs -->
                <tr>
                    <td colspan="2" class="text-end py-1 pe-3 border-primary" style="border-width: 2px">TOTAL DES DEBITEURS</td>
                    <td colspan="4" class="py-1 border-primary text-center" style="border-width: 2px">
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
