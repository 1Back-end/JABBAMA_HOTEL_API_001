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
            <th colspan="5" style="font-size: 8.5px;">{{ $cat }}</th>
        @endforeach

        @php
            $hasAnyBar = collect($orders)->contains(fn($o) => !empty($o['drinks']));
            $hasAnyDivers = collect($orders)->contains(function($o) {
                $divs = [];
                if(isset($o['debiteurs']) && is_array($o['debiteurs'])) {
                    foreach($o['debiteurs'] as $deb) {
                        if(!empty($deb['items']) || !empty($deb['drinks'])) $divs[] = true;
                    }
                } elseif(isset($o['divers']) && is_array($o['divers'])) {
                    $divs = $o['divers'];
                }
                return !empty($divs);
            });
        @endphp

        @if($hasAnyBar)
            <th colspan="5" style="font-size: 8.5px;">BAR</th>
        @endif

        @if($hasAnyDivers)
            <th colspan="4" style="font-size: 8.5px;">DIVERS</th>
        @endif

        <th rowspan="2" style="width: 10%; font-size: 8.5px;">PAIEMENT</th>
        <th rowspan="2" style="width: 10%; font-size: 8.5px;">MONTANT</th>
    </tr>
    <tr>
        @foreach($categories as $cat)
            <th class="sub-th" style="font-size: 8px;">LIBELLÉ</th>
            <th class="sub-th" style="font-size: 8px;">QTÉ</th>
            <th class="sub-th" style="font-size: 8px;">P.U</th>
            <th class="sub-th" style="font-size: 8px;">P.T</th>
            <th class="sub-th" style="font-size: 8px;">R/S</th>
        @endforeach

        @if($hasAnyBar)
            <th class="sub-th" style="font-size: 8px;">LIBELLÉ</th>
            <th class="sub-th" style="font-size: 8px;">QTÉ</th>
            <th class="sub-th" style="font-size: 8px;">P.U</th>
            <th class="sub-th" style="font-size: 8px;">P.T</th>
            <th class="sub-th" style="font-size: 8px;">R/S</th>
        @endif

        @if($hasAnyDivers)
            <th class="sub-th" style="font-size: 8px;">LIBELLÉ</th>
            <th class="sub-th" style="font-size: 8px;">QTÉ</th>
            <th class="sub-th" style="font-size: 8px;">P.U</th>
            <th class="sub-th" style="font-size: 8px;">R/S</th>
        @endif
    </tr>
    </thead>
    <tbody>
    @forelse($orders as $order)
        @php
            $orderCatItems = [];
            foreach($categories as $cat) {
                $orderCatItems[$cat] = [];
            }

            $items = $order['items'] ?? [];
            foreach($items as $item) {
                $itemCat = strtoupper(trim($item['sales_category'] ?? $item['category'] ?? ($order['sales_category'] ?? '')));
                if(isset($orderCatItems[$itemCat])) {
                    $orderCatItems[$itemCat][] = $item;
                } else {
                    $fallbackCat = strtoupper(trim($order['sales_category'] ?? ''));
                    if(isset($orderCatItems[$fallbackCat])) {
                        $orderCatItems[$fallbackCat][] = $item;
                    } elseif(count($categories) > 0) {
                        $orderCatItems[$categories[0]][] = $item;
                    }
                }
            }

            $barItems = $order['drinks'] ?? [];

            $diversItems = [];
            if(isset($order['debiteurs']) && is_array($order['debiteurs'])) {
                foreach($order['debiteurs'] as $deb) {
                    if(isset($deb['items']) && is_array($deb['items'])) {
                        foreach($deb['items'] as $dItem) { $diversItems[] = $dItem; }
                    }
                    if(isset($deb['drinks']) && is_array($deb['drinks'])) {
                        foreach($deb['drinks'] as $dDrink) { $diversItems[] = $dDrink; }
                    }
                }
            } elseif(isset($order['divers']) && is_array($order['divers'])) {
                $diversItems = $order['divers'];
            }

            $rsPrice = $order['price_for_room_service'] ?? $order['room_service_price'] ?? 0;

            $counts = [1];
            foreach($categories as $cat) {
                $counts[] = count($orderCatItems[$cat]);
            }
            if($hasAnyBar) $counts[] = count($barItems);
            if($hasAnyDivers) $counts[] = count($diversItems);
            $maxLines = max($counts);
            $middleIndex = (int) floor($maxLines / 2);

            $targetInfo = null;
            if ($rsPrice > 0) {
                foreach($categories as $catIdx => $cat) {
                    if (isset($orderCatItems[$cat][$middleIndex])) {
                        $targetInfo = ['type' => 'cat', 'key' => $cat];
                        break;
                    }
                }
                if (!$targetInfo && $hasAnyBar && isset($barItems[$middleIndex])) {
                    $targetInfo = ['type' => 'bar', 'key' => null];
                }
                if (!$targetInfo && $hasAnyDivers && isset($diversItems[$middleIndex])) {
                    $targetInfo = ['type' => 'divers', 'key' => null];
                }

                if (!$targetInfo) {
                    foreach($categories as $cat) {
                        if (!empty($orderCatItems[$cat])) {
                            $targetInfo = ['type' => 'cat', 'key' => $cat];
                            break;
                        }
                    }
                }
            }
        @endphp

        @for($i = 0; $i < $maxLines; $i++)
            @php
                $isMiddleRow = ($i === $middleIndex);
            @endphp
            <tr>
                @if($i === 0)
                    <td rowspan="{{ $maxLines }}" class="fw-bold" style="white-space: nowrap; font-size: 7.5px;">{{ $order['code_facture'] ?? '' }}</td>
                    <td rowspan="{{ $maxLines }}">{{ $order['no_table'] ?? '' }}</td>
                    <td rowspan="{{ $maxLines }}">{{ $order['chambre'] ?? '' }}</td>
                @endif


                @foreach($categories as $cat)
                    @php
                        $item = $orderCatItems[$cat][$i] ?? null;
                        $showRsHere = ($isMiddleRow && $targetInfo && $targetInfo['type'] === 'cat' && $targetInfo['key'] === $cat);
                    @endphp
                    <td class="text-start {{ $item ? 'bg-light' : '' }}">{{ $item['menu'] ?? '' }}</td>
                    <td class="{{ $item ? 'bg-light' : '' }}">{{ $item ? $item['quantity'] : '' }}</td>
                    <td class="{{ $item ? 'bg-light' : '' }}">{{ $item ? \App\Helpers\FormatPrice::format($item['unit_price']) : '' }}</td>
                    <td class="{{ $item ? 'bg-light' : '' }}">{{ $item ? \App\Helpers\FormatPrice::format($item['total_price']) : '' }}</td>
                    <td class="fw-bold {{ $item ? 'bg-light' : '' }}">{{ $showRsHere ? \App\Helpers\FormatPrice::format($rsPrice) : '' }}</td>
                @endforeach

                {{-- Colonnes Bar --}}
                @if($hasAnyBar)
                    @php
                        $drink = $barItems[$i] ?? null;
                        $showRsBar = ($isMiddleRow && $targetInfo && $targetInfo['type'] === 'bar');
                    @endphp
                    <td class="text-start {{ $drink ? 'bg-light' : '' }}">{{ $drink['menu'] ?? '' }}</td>
                    <td class="{{ $drink ? 'bg-light' : '' }}">{{ $drink ? $drink['quantity'] : '' }}</td>
                    <td class="{{ $drink ? 'bg-light' : '' }}">{{ $drink ? \App\Helpers\FormatPrice::format($drink['unit_price']) : '' }}</td>
                    <td class="{{ $drink ? 'bg-light' : '' }}">{{ $drink ? \App\Helpers\FormatPrice::format($drink['total_price']) : '' }}</td>
                    <td class="fw-bold">{{ $showRsBar ? \App\Helpers\FormatPrice::format($rsPrice) : '' }}</td>
                @endif

                {{-- Colonnes Divers --}}
                @if($hasAnyDivers)
                    @php
                        $divers = $diversItems[$i] ?? null;
                        $showRsDivers = ($isMiddleRow && $targetInfo && $targetInfo['type'] === 'divers');
                    @endphp
                    <td class="text-start {{ $divers ? 'bg-light' : '' }}">{{ $divers['menu'] ?? '' }}</td>
                    <td class="{{ $divers ? 'bg-light' : '' }}">{{ $divers ? $divers['quantity'] : '' }}</td>
                    <td class="{{ $divers ? 'bg-light' : '' }}">{{ $divers ? \App\Helpers\FormatPrice::format($divers['unit_price']) : '' }}</td>
                    <td class="fw-bold">{{ $showRsDivers ? \App\Helpers\FormatPrice::format($rsPrice) : '' }}</td>
                @endif

                @if($i === 0)
                    <td rowspan="{{ $maxLines }}">
                        <div class="fw-bold">{{ $order['regulation_status'] ?? '' }}</div>
                    </td>
                    <td rowspan="{{ $maxLines }}" class="fw-bold">
                        <div>{{ \App\Helpers\FormatPrice::format($order['total_amount'] ?? 0) }}</div>
                        @if(!empty($order['payment_methods']))
                            @foreach($order['payment_methods'] as $pm)
                                <div style="font-size: 6px; color: #555;">{{ $pm['method_name'] ?? '' }} ({{ \App\Helpers\FormatPrice::format($pm['amount'] ?? 0) }})</div>
                            @endforeach
                        @endif
                    </td>
                @endif
            </tr>
        @endfor
    @empty
        <tr>
            <td colspan="10" class="text-center">Aucune donnée disponible pour cette date.</td>
        </tr>
    @endforelse
    </tbody>
</table>
</body>
</html>
