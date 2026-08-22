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

        /* Configuration des marges et en-tête automatique pour la page 2 et suivantes */
        /* Configuration des marges et en-tête automatique pour la page 2 et suites */
        @page {
            size: A3 landscape;
            margin: 8mm 5mm 5mm 5mm; /* Réduction de la marge du haut à 8mm au lieu de 15mm */
            @top-center {
                content: "SUITE DE LA MAIN COURANTE DU RESTAURANT DU {{ \Carbon\Carbon::parse($date)->locale('fr')->isoFormat('D MMMM YYYY') }}";
                font-family: "Merriweather", serif;
                font-size: 11px;
                font-weight: bold;
                text-transform: uppercase;
            }
        }

        /* Empêche l'en-tête personnalisé d'apparaître sur la toute première page */
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
        Main courante du restaurant du {{ \Carbon\Carbon::parse($date)->locale('fr')->isoFormat('dddd D MMMM YYYY') }}
    </div>
</header>

<div class="mt-2 w-100" style="border-top: 1px double rgba(0,0,0,0.75); margin-bottom: 2px"></div>
<div class="mb-2 w-100" style="border-top: 1px double rgba(0,0,0,0.75);"></div>

<p class="fst-italic text-end">
    Date d'impression : {{ now()->format('d/m/Y H:i') }}
</p>

<table class="table table-bordered table-striped border-black" style="font-size: 11px;">
    <thead>
    <tr>
        <th>TOTAL GLE</th>
        <th>ENCAISSEMENT</th>
        <th>DÉBITEUR</th>
        <th>T.P.DÉJEUNER</th>
        <th>T.DÉJEUNER</th>
        <th>T.DINER</th>
        <th>T.BAR</th>
        <th>T.DIVERS</th>
    </tr>
    </thead>
    <tbody>
    <tr>
        <td class="fw-bold">{{ \App\Helpers\FormatPrice::format($total_gle) }}</td>
        <td class="text-success fw-bold">{{ \App\Helpers\FormatPrice::format($total_encaissement) }}</td>
        <td class="text-danger fw-bold">{{ \App\Helpers\FormatPrice::format($total_debiteur) }}</td>
        <td>{{ \App\Helpers\FormatPrice::format($amounts_by_category['PETIT DEJEUNER'] ?? 0) }}</td>
        <td>{{ \App\Helpers\FormatPrice::format($amounts_by_category['DEJEUNER'] ?? 0) }}</td>
        <td>{{ \App\Helpers\FormatPrice::format($amounts_by_category['DINNER'] ?? 0) }}</td>
        <td>{{ \App\Helpers\FormatPrice::format($total_bar_amount) }}</td>
        <td>{{ \App\Helpers\FormatPrice::format($total_divers_amount) }}</td>
    </tr>
    </tbody>
</table>
<table class="table table-bordered table-striped border-black" style="font-size: 11px;">
    <thead>
    <tr>
        <th>TOTAL ROOM/SERVICE</th>
        <th>N.P.DÉJEUNER</th>
        <th>N.DÉJEUNER</th>
        <th>N.DINER</th>
        <th>N.BAR</th>
        <th>N.ROOM SERVICE</th>
        <th>N.DIVERS</th>
    </tr>
    </thead>
    <tbody>
    <tr>
        <td class="fw-bold">{{ \App\Helpers\FormatPrice::format($total_room_service_amount) }}</td>
        <td>{{ $counts_by_category['PETIT DEJEUNER'] ?? 0 }}</td>
        <td>{{ $counts_by_category['DEJEUNER'] ?? 0 }}</td>
        <td>{{ $counts_by_category['DINNER'] ?? 0 }}</td>
        <td>{{ $total_bar_count }}</td>
        <td>{{ $total_room_service_quantity }}</td>
        <td>{{ $total_divers_count }}</td>
    </tr>
    </tbody>
</table>

@php
    $categories = array_keys($amounts_by_category ?? []);
@endphp

<table class="table table-bordered table-striped border-black" style="font-size: 9px;">
    <thead>
    <tr>
        <th rowspan="2" style="width: 12%; font-size: 8.5px;">N° FACT</th>
        <th rowspan="2" style="width: 4%; font-size: 8.5px;">TABLE</th>
        <th rowspan="2" style="width: 5%; font-size: 8.5px;">CHAMBRE</th>

        @foreach($categories as $cat)
            <th colspan="5" style="width: 16%; font-size: 8.5px;">{{ $cat }}</th>
        @endforeach

        <th colspan="5" style="width: 15%; font-size: 8.5px;">BAR</th>
        <th colspan="4" style="width: 14%; font-size: 8.5px;">DIVERS</th>

        <th rowspan="2" style="width: 10%; font-size: 8.5px;">PAIEMENT</th>
        <th rowspan="2" style="width: 10%; font-size: 8.5px;">MONTANT</th>
    </tr>
    <tr>
        @foreach($categories as $cat)
            <th class="sub-th" style="width: 5.5%; font-size: 8px;">LIBELLÉ</th>
            <th class="sub-th" style="width: 2%; font-size: 8px;">QTÉ</th>
            <th class="sub-th" style="width: 2.8%; font-size: 8px;">P.U</th>
            <th class="sub-th" style="width: 2.8%; font-size: 8px;">P.T</th>
            <th class="sub-th" style="width: 1.9%; font-size: 8px;">R/S</th>
        @endforeach

        {{-- Sous-colonnes Bar --}}
        <th class="sub-th" style="width: 5.5%; font-size: 8px;">LIBELLÉ</th>
        <th class="sub-th" style="width: 2%; font-size: 8px;">QTÉ</th>
        <th class="sub-th" style="width: 2.8%; font-size: 8px;">P.U</th>
        <th class="sub-th" style="width: 2.8%; font-size: 8px;">P.T</th>
        <th class="sub-th" style="width: 1.9%; font-size: 8px;">R/S</th>

        {{-- Sous-colonnes Divers --}}
        <th class="sub-th" style="width: 6%; font-size: 8px;">LIBELLÉ</th>
        <th class="sub-th" style="width: 2%; font-size: 8px;">QTÉ</th>
        <th class="sub-th" style="width: 2.5%; font-size: 8px;">P.U</th>
        <th class="sub-th" style="width: 1.9%; font-size: 8px;">R/S</th>
    </tr>
    </thead>
    <tbody>
    @forelse($orders as $order)
        @php
            $orderCatItems = [];
            foreach($categories as $cat) {
                $orderCatItems[$cat] = [];
            }

            $catName = strtoupper(trim($order['sales_category'] ?? ''));
            if(isset($orderCatItems[$catName])) {
                $orderCatItems[$catName] = $order['items'] ?? [];
            }

            $barItems = $order['drinks'] ?? [];
            $diversItems = $order['divers'] ?? [];

            $rsPrice = $order['room_service_price'] ?? 0;

            $counts = [1];
            foreach($categories as $cat) {
                $counts[] = count($orderCatItems[$cat]);
            }
            $counts[] = count($barItems);
            $counts[] = count($diversItems);
            $maxLines = max($counts);
        @endphp

        @for($i = 0; $i < $maxLines; $i++)
            <tr>
                @if($i === 0)
                    <td rowspan="{{ $maxLines }}" class="fw-bold" style="white-space: nowrap; font-size: 7.5px;">{{ $order['code_facture'] }}</td>
                    <td rowspan="{{ $maxLines }}">{{ $order['no_table'] }}</td>
                    <td rowspan="{{ $maxLines }}">{{ $order['chambre'] ?? '' }}</td>
                @endif

                {{-- Catégories dynamiques (ex: Petit Déjeuner) --}}
                @foreach($categories as $cat)
                    @php
                        $item = $orderCatItems[$cat][$i] ?? null;
                    @endphp
                    <td class="text-start">{{ $item['menu'] ?? '' }}</td>
                    <td>{{ $item ? $item['quantity'] : '' }}</td>
                    <td>{{ $item ? \App\Helpers\FormatPrice::format($item['unit_price']) : '' }}</td>
                    <td>{{ $item ? \App\Helpers\FormatPrice::format($item['total_price']) : '' }}</td>
                    {{-- R/S s'affiche uniquement si la ligne possède un article ET que rsPrice > 0 --}}
                    <td>{{ ($item && $rsPrice > 0) ? \App\Helpers\FormatPrice::format($rsPrice) : '' }}</td>
                @endforeach

                {{-- Bar --}}
                @php $drink = $barItems[$i] ?? null; @endphp
                <td class="text-start">{{ $drink['menu'] ?? '' }}</td>
                <td>{{ $drink ? $drink['quantity'] : '' }}</td>
                <td>{{ $drink ? \App\Helpers\FormatPrice::format($drink['unit_price']) : '' }}</td>
                <td>{{ $drink ? \App\Helpers\FormatPrice::format($drink['total_price']) : '' }}</td>
                <td>{{ ($drink && $rsPrice > 0) ? \App\Helpers\FormatPrice::format($rsPrice) : '' }}</td>

                {{-- Divers --}}
                @php $divers = $diversItems[$i] ?? null; @endphp
                <td class="text-start">{{ $divers['menu'] ?? '' }}</td>
                <td>{{ $divers ? $divers['quantity'] : '' }}</td>
                <td>{{ $divers ? \App\Helpers\FormatPrice::format($divers['unit_price']) : '' }}</td>
                <td>{{ ($divers && $rsPrice > 0) ? \App\Helpers\FormatPrice::format($rsPrice) : '' }}</td>

                @if($i === 0)
                    <td rowspan="{{ $maxLines }}">
                        <div class="fw-bold">{{ $order['regulation_status'] }}</div>

                    </td>
                    <td rowspan="{{ $maxLines }}" class="fw-bold">
                        @if(!empty($order['payment_methods']))
                            @foreach($order['payment_methods'] as $pm)
                                <div style="font-size: 6px; color: #555;">{{ $pm['method_name'] }} ({{ \App\Helpers\FormatPrice::format($pm['amount']) }})</div>
                            @endforeach
                        @endif
                    </td>
                @endif
            </tr>
        @endfor
    @empty
        <tr>
            <td colspan="{{ 3 + (count($categories) * 5) + 5 + 4 + 2 }}" class="text-center">Aucune commande enregistrée pour cette date.</td>
        </tr>
    @endforelse
    </tbody>
</table>
</body>
</html>
