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
            margin-bottom: 15px;
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
        MENUS, BOISSONS ET COMPLÉMENTS LES PLUS VENDUS
    </div>

    @if(!empty($start_date) && !empty($end_date))
        <h3 style="font-style: italic; font-size: 11px; margin-top: 5px;">
            Période : {{ $start_date }} au {{ $end_date }}
        </h3>
    @endif
</header>

<div class="mt-2 w-100" style="border-top: 1px double rgba(0,0,0,0.75); margin-bottom: 2px"></div>
<div class="mb-2 w-100" style="border-top: 1px double rgba(0,0,0,0.75);"></div>

<p class="fst-italic text-end" style="font-size: 12px; margin-bottom: 10px;">
    Date d'impression : {{ now()->format('d/m/Y H:i') }}
</p>

<div class="mt-2">

    <!-- 1. TABLEAU MENUS -->
    <div class="col-12 mt-1 mb-3">
        <div class="table-responsive">
            <table class="table table-sm table-bordered report-table mb-0 align-middle">
                <thead>
                <tr class="header-main text-center bg-light">
                    <th colspan="4" class="text-uppercase fw-bold py-2" style="font-size: 3.5mm;">
                        MENUS LES PLUS CONSOMMÉS
                    </th>
                </tr>
                <tr class="header-sub text-center text-uppercase fw-bold bg-light" style="font-size: 2.5mm;">
                    <th style="width: 10%">RANG</th>
                    <th style="width: 50%">LIBELLÉ</th>
                    <th style="width: 20%">QUANTITÉ</th>
                    <th style="width: 20%">MONTANT</th>
                </tr>
                </thead>
                <tbody>
                @if(!empty($top_menus) && count($top_menus) > 0)
                    @foreach($top_menus as $index => $item)
                        <tr>
                            <td class="text-center fw-bold text-secondary">#{{ $index + 1 }}</td>
                            <td class="text-start ps-3">
                                <span class="fw-semibold text-uppercase text-primary">{{ $item['name'] }}</span>
                            </td>
                            <td class="text-center fw-bold">{{ $item['total_quantity'] }}</td>
                            <td class="text-end fw-bold pe-2">{{ \App\Helpers\FormatPrice::format($item['total_revenue']) }}</td>
                        </tr>
                    @endforeach
                @else
                    <tr>
                        <td colspan="4" class="text-center text-muted py-2">Aucun menu trouvé.</td>
                    </tr>
                @endif
                </tbody>
            </table>
        </div>
    </div>

    <!-- 2. TABLEAU BOISSONS -->
    <div class="col-12 mt-3 mb-3">
        <div class="table-responsive">
            <table class="table table-sm table-bordered report-table mb-0 align-middle">
                <thead>
                <tr class="header-main text-center bg-light">
                    <th colspan="4" class="text-uppercase text-dark fw-bold py-2" style="font-size: 3.5mm;">
                        BOISSONS LES PLUS CONSOMMÉES
                    </th>
                </tr>
                <tr class="header-sub text-center text-uppercase fw-bold bg-light" style="font-size: 2.5mm;">
                    <th style="width: 10%">RANG</th>
                    <th style="width: 50%">LIBELLÉ</th>
                    <th style="width: 20%">QUANTITÉ</th>
                    <th style="width: 20%">MONTANT</th>
                </tr>
                </thead>
                <tbody>
                @if(!empty($top_drinks) && count($top_drinks) > 0)
                    @foreach($top_drinks as $index => $drink)
                        <tr>
                            <td class="text-center fw-bold text-secondary">#{{ $index + 1 }}</td>
                            <td class="text-start ps-3">
                                <span class="fw-semibold text-uppercase text-primary">{{ $drink['name'] }}</span>
                            </td>
                            <td class="text-center fw-bold">{{ $drink['total_quantity'] }}</td>
                            <td class="text-end fw-bold pe-2">{{ \App\Helpers\FormatPrice::format($drink['total_revenue']) }}</td>
                        </tr>
                    @endforeach
                @else
                    <tr>
                        <td colspan="4" class="text-center text-muted py-2">Aucune boisson trouvée.</td>
                    </tr>
                @endif
                </tbody>
            </table>
        </div>
    </div>

    <!-- 3. TABLEAU COMPLÉMENTS -->
    <div class="col-12 mt-3 mb-3">
        <div class="table-responsive">
            <table class="table table-sm table-bordered report-table mb-0 align-middle">
                <thead>
                <tr class="header-main text-center bg-light">
                    <th colspan="4" class="text-uppercase text-dark fw-bold py-2" style="font-size: 3.5mm;">
                        COMPLÉMENTS LES PLUS CONSOMMÉS
                    </th>
                </tr>
                <tr class="header-sub text-center text-uppercase fw-bold bg-light" style="font-size: 2.5mm;">
                    <th style="width: 10%">RANG</th>
                    <th style="width: 50%">LIBELLÉ</th>
                    <th style="width: 20%">QUANTITÉ</th>
                    <th style="width: 20%">MONTANT</th>
                </tr>
                </thead>
                <tbody>
                @if(!empty($top_complements) && count($top_complements) > 0)
                    @foreach($top_complements as $index => $complement)
                        <tr>
                            <td class="text-center fw-bold text-secondary">#{{ $index + 1 }}</td>
                            <td class="text-start ps-3">
                                <span class="fw-semibold text-uppercase text-primary">{{ $complement['name'] }}</span>
                            </td>
                            <td class="text-center fw-bold">{{ $complement['total_quantity'] }}</td>
                            <td class="text-end fw-bold pe-2">{{ \App\Helpers\FormatPrice::format($complement['total_revenue']) }}</td>
                        </tr>
                    @endforeach
                @else
                    <tr>
                        <td colspan="4" class="text-center text-muted py-2">Aucun complément trouvé.</td>
                    </tr>
                @endif
                </tbody>
            </table>
        </div>
    </div>

    <!-- 4. TABLEAU BOISSONS CHAUDES -->
    <div class="col-12 mt-3 mb-3">
        <div class="table-responsive">
            <table class="table table-sm table-bordered report-table mb-0 align-middle">
                <thead>
                <tr class="header-main text-center bg-light">
                    <th colspan="4" class="text-uppercase text-dark fw-bold py-2" style="font-size: 3.5mm;">
                        BOISSONS CHAUDES LES PLUS CONSOMMÉES
                    </th>
                </tr>
                <tr class="header-sub text-center text-uppercase fw-bold bg-light" style="font-size: 2.5mm;">
                    <th style="width: 10%">RANG</th>
                    <th style="width: 50%">LIBELLÉ</th>
                    <th style="width: 20%">QUANTITÉ</th>
                    <th style="width: 20%">MONTANT</th>
                </tr>
                </thead>
                <tbody>
                @if(!empty($top_drinks_from_complements) && count($top_drinks_from_complements) > 0)
                    @foreach($top_drinks_from_complements as $index => $item)
                        <tr>
                            <td class="text-center fw-bold text-secondary">#{{ $index + 1 }}</td>
                            <td class="text-start ps-3">
                                <span class="fw-semibold text-uppercase text-primary">{{ $item['name'] }}</span>
                            </td>
                            <td class="text-center fw-bold">{{ $item['total_quantity'] }}</td>
                            <td class="text-end fw-bold pe-2">{{ \App\Helpers\FormatPrice::format($item['total_revenue']) }}</td>
                        </tr>
                    @endforeach
                @else
                    <tr>
                        <td colspan="4" class="text-center text-muted py-2">Aucune boisson chaude trouvée.</td>
                    </tr>
                @endif
                </tbody>
            </table>
        </div>
    </div>

</div>


</body>
</html>
