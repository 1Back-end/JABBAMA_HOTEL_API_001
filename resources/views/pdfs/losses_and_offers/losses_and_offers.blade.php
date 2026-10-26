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
        STATISTIQUES DES PERTES (MENUS DÉFECTUEUX)
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
<p class="fst-italic text-end" style="font-size: 12px; margin-bottom: 10px;">
    Date d'impression : {{ now()->format('d/m/Y H:i') }}
</p>

<!-- Conteneur des tableaux -->
<div class="mt-2">

    <!-- TABLEAU 1 : MENUS DÉFECTUEUX -->
    <div class="col-12 mt-1 mb-4">
        <div class="table-responsive">
            <table class="table table-sm table-bordered report-table mb-0 align-middle" style="font-size: 11px; width: 100%;">
                <thead>
                <tr class="header-main text-center bg-light">
                    <th colspan="7" class="text-uppercase text-dark fw-bold py-2">
                        MENUS DÉFECTUEUX (PERTES ET RATIOS)
                    </th>
                </tr>
                <tr class="header-sub text-center text-uppercase fw-bold bg-light">
                    <th style="width: 5%">#</th>
                    <th style="width: 25%">LIBELLE</th>
                    <th style="width: 12%">NOMBRE DE COMMANDES</th>
                    <th style="width: 13%">QTÉ COMMANDÉE</th>
                    <th style="width: 13%">QTÉ DÉFECTUEUSE</th>
                    <th style="width: 12%">RATIO</th>
                    <th style="width: 20%">MONTANT DE PERTE</th>
                </tr>
                </thead>
                <tbody>
                @forelse($top_defective_menus as $index => $item)
                    <tr>
                        <td class="text-center fw-bold">{{ $index + 1 }}</td>
                        <td class="text-start ps-3 fw-semibold text-uppercase text-primary">{{ $item['name'] }}</td>
                        <td class="text-center">{{ $item['total_times_ordered'] }}</td>
                        <td class="text-center">{{ $item['total_ordered_quantity'] }}</td>
                        <td class="text-center fw-bold bg-light">{{ $item['total_defective_quantity'] }}</td>
                        <td class="text-center fw-bold text-danger">{{ $item['defective_ratio_percentage'] }}</td>
                        <td class="text-end fw-bold">{{ \App\Helpers\FormatPrice::format($item['total_revenue_loss'] ?? 0) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center text-muted py-3">
                            Aucune donnée de menu défectueux trouvée pour cette période.
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- TABLEAU 2 : COMPLÉMENTS DÉFECTUEUX -->
    <div class="col-12 mt-1 mb-4">
        <div class="table-responsive">
            <table class="table table-sm table-bordered report-table mb-0 align-middle" style="font-size: 11px; width: 100%;">
                <thead>
                <tr class="header-main text-center bg-light">
                    <th colspan="7" class="text-uppercase text-dark fw-bold py-2">
                        COMPLÉMENTS DÉFECTUEUX (PERTES ET RATIOS)
                    </th>
                </tr>
                <tr class="header-sub text-center text-uppercase fw-bold bg-light">
                    <th style="width: 5%">#</th>
                    <th style="width: 25%">LIBELLE</th>
                    <th style="width: 12%">NOMBRE DE COMMANDES</th>
                    <th style="width: 13%">QTÉ COMMANDÉE</th>
                    <th style="width: 13%">QTÉ DÉFECTUEUSE</th>
                    <th style="width: 12%">RATIO</th>
                    <th style="width: 20%">MONTANT DE PERTE</th>
                </tr>
                </thead>
                <tbody>
                @forelse($top_defective_complements as $index => $item)
                    <tr>
                        <td class="text-center fw-bold">{{ $index + 1 }}</td>
                        <td class="text-start ps-3 fw-semibold text-uppercase text-success">{{ $item['name'] }}</td>
                        <td class="text-center">{{ $item['total_times_ordered'] }}</td>
                        <td class="text-center">{{ $item['total_ordered_quantity'] }}</td>
                        <td class="text-center fw-bold bg-light">{{ $item['total_defective_quantity'] }}</td>
                        <td class="text-center fw-bold text-danger">{{ $item['defective_ratio_percentage'] }}</td>
                        <td class="text-end fw-bold">{{ \App\Helpers\FormatPrice::format($item['total_revenue_loss'] ?? 0) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center text-muted py-3">
                            Aucune donnée de complément défectueux trouvée pour cette période.
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- TABLEAU 3 : BOISSONS CHAUDES DÉFECTUEUSES -->
    <div class="col-12 mt-1 mb-4">
        <div class="table-responsive">
            <table class="table table-sm table-bordered report-table mb-0 align-middle" style="font-size: 11px; width: 100%;">
                <thead>
                <tr class="header-main text-center bg-light">
                    <th colspan="7" class="text-uppercase text-dark fw-bold py-2">
                        BOISSONS CHAUDES DÉFECTUEUSES (PERTES ET RATIOS)
                    </th>
                </tr>
                <tr class="header-sub text-center text-uppercase fw-bold bg-light">
                    <th style="width: 5%">#</th>
                    <th style="width: 25%">LIBELLE</th>
                    <th style="width: 12%">NOMBRE DE COMMANDES</th>
                    <th style="width: 13%">QTÉ COMMANDÉE</th>
                    <th style="width: 13%">QTÉ DÉFECTUEUSE</th>
                    <th style="width: 12%">RATIO</th>
                    <th style="width: 20%">MONTANT DE PERTE</th>
                </tr>
                </thead>
                <tbody>
                @forelse($top_defective_drinks as $index => $item)
                    <tr>
                        <td class="text-center fw-bold">{{ $index + 1 }}</td>
                        <td class="text-start ps-3 fw-semibold text-uppercase text-primary">{{ $item['name'] }}</td>
                        <td class="text-center">{{ $item['total_times_ordered'] }}</td>
                        <td class="text-center">{{ $item['total_ordered_quantity'] }}</td>
                        <td class="text-center fw-bold bg-light">{{ $item['total_defective_quantity'] }}</td>
                        <td class="text-center fw-bold text-danger">{{ $item['defective_ratio_percentage'] }}</td>
                        <td class="text-end fw-bold">{{ \App\Helpers\FormatPrice::format($item['total_revenue_loss'] ?? 0) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center text-muted py-3">
                            Aucune donnée de boisson chaude défectueuse trouvée pour cette période.
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- TABLEAU 4 : BOISSONS BAR / DIRECTES DÉFECTUEUSES -->
    <div class="col-12 mt-1 mb-4">
        <div class="table-responsive">
            <table class="table table-sm table-bordered report-table mb-0 align-middle" style="font-size: 11px; width: 100%;">
                <thead>
                <tr class="header-main text-center bg-light">
                    <th colspan="7" class="text-uppercase text-dark fw-bold py-2">
                        BOISSONS BAR / DIRECTES DÉFECTUEUSES (PERTES ET RATIOS)
                    </th>
                </tr>
                <tr class="header-sub text-center text-uppercase fw-bold bg-light">
                    <th style="width: 5%">#</th>
                    <th style="width: 25%">LIBELLE</th>
                    <th style="width: 12%">NOMBRE DE COMMANDES</th>
                    <th style="width: 13%">QTÉ COMMANDÉE</th>
                    <th style="width: 13%">QTÉ DÉFECTUEUSE</th>
                    <th style="width: 12%">RATIO</th>
                    <th style="width: 20%">MONTANT DE PERTE</th>
                </tr>
                </thead>
                <tbody>
                @forelse($top_defective_others_drinks as $index => $item)
                    <tr>
                        <td class="text-center fw-bold">{{ $index + 1 }}</td>
                        <td class="text-start ps-3 fw-semibold text-uppercase text-primary">{{ $item['name'] }}</td>
                        <td class="text-center">{{ $item['total_times_ordered'] }}</td>
                        <td class="text-center">{{ $item['total_ordered_quantity'] }}</td>
                        <td class="text-center fw-bold bg-light">{{ $item['total_defective_quantity'] }}</td>
                        <td class="text-center fw-bold text-danger">{{ $item['defective_ratio_percentage'] }}</td>
                        <td class="text-end fw-bold">{{ \App\Helpers\FormatPrice::format($item['total_revenue_loss'] ?? 0) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center text-muted py-3">
                            Aucune donnée de boisson bar défectueuse trouvée pour cette période.
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

</div>


</body>
</html>
