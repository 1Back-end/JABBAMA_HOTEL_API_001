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
        Historique de consommation
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

    @if(isset($clients) && count($clients) > 0)
        @foreach($clients as $client)
            <div class="card mb-3 p-2 border">
                <div class="card-header bg-light mb-2 py-2">
                    <h5 class="fw-bold text-primary m-0 text-uppercase" style="font-size: 4mm;">
                        {{ $client['client_name'] }}
                        @if(!empty($client['client_code']))
                            <small class="text-muted">({{ $client['client_code'] }})</small>
                        @endif
                    </h5>
                </div>
                <div class="card-body p-0">
                    <div class="row">
                        <div class="mb-3" style="width: 50%; float: left; padding: 5px;">
                            <table class="table table-sm table-bordered report-table mb-0 align-middle" style="font-size: 10px">
                                <thead>
                                <tr class="header-main text-center bg-light">
                                    <th colspan="3" class="text-uppercase text-dark fw-bold py-1" style="font-size: 3mm;">
                                        MENUS LES PLUS CONSOMMÉS
                                    </th>
                                </tr>
                                <tr class="header-sub text-center text-uppercase fw-bold bg-light" style="font-size: 2.5mm;">
                                    <th style="width: 50%">LIBELLE</th>
                                    <th style="width: 20%">QTÉ</th>
                                    <th style="width: 30%">MONTANT</th>
                                </tr>
                                </thead>
                                <tbody>
                                @if(!empty($client['top_menus']) && count($client['top_menus']) > 0)
                                    @foreach($client['top_menus'] as $m)
                                        <tr>
                                            <td class="text-start ps-2">{{ $m['name'] }}</td>
                                            <td class="text-center fw-bold">{{ $m['total_quantity'] }}</td>
                                            <td class="text-end fw-bold">{{ \App\Helpers\FormatPrice::format($m['total_revenue']) }}</td>
                                        </tr>
                                    @endforeach
                                @else
                                    <tr>
                                        <td colspan="3" class="text-center text-muted small py-1">Aucun menu</td>
                                    </tr>
                                @endif
                                </tbody>
                            </table>
                        </div>

                        <div class="mb-3" style="width: 50%; float: left; padding: 5px;">
                            <table class="table table-sm table-bordered report-table mb-0 align-middle" style="font-size: 10px">
                                <thead>
                                <tr class="header-main text-center bg-light">
                                    <th colspan="3" class="text-uppercase text-dark fw-bold py-1" style="font-size: 3mm;">
                                        BOISSONS LES PLUS CONSOMMÉES
                                    </th>
                                </tr>
                                <tr class="header-sub text-center text-uppercase fw-bold bg-light" style="font-size: 2.5mm;">
                                    <th style="width: 50%">LIBELLE</th>
                                    <th style="width: 20%">QTÉ</th>
                                    <th style="width: 30%">MONTANT</th>
                                </tr>
                                </thead>
                                <tbody>
                                @if(!empty($client['top_drinks']) && count($client['top_drinks']) > 0)
                                    @foreach($client['top_drinks'] as $d)
                                        <tr>
                                            <td class="text-start ps-2">{{ $d['name'] }}</td>
                                            <td class="text-center fw-bold">{{ $d['total_quantity'] }}</td>
                                            <td class="text-end fw-bold">{{ \App\Helpers\FormatPrice::format($d['total_revenue']) }}</td>
                                        </tr>
                                    @endforeach
                                @else
                                    <tr>
                                        <td colspan="3" class="text-center text-muted small py-1">Aucune boisson</td>
                                    </tr>
                                @endif
                                </tbody>
                            </table>
                        </div>

                        <!-- 3. Compléments -->
                        <div class="mb-3" style="width: 50%; float: left; padding: 5px;">
                            <table class="table table-sm table-bordered report-table mb-0 align-middle" style="font-size: 10px">
                                <thead>
                                <tr class="header-main text-center bg-light">
                                    <th colspan="3" class="text-uppercase text-dark fw-bold py-1" style="font-size: 3mm;">
                                        COMPLÉMENTS LES PLUS CONSOMMÉS
                                    </th>
                                </tr>
                                <tr class="header-sub text-center text-uppercase fw-bold bg-light" style="font-size: 2.5mm;">
                                    <th style="width: 50%">LIBELLE</th>
                                    <th style="width: 20%">QTÉ</th>
                                    <th style="width: 30%">MONTANT</th>
                                </tr>
                                </thead>
                                <tbody>
                                @if(!empty($client['top_complements']) && count($client['top_complements']) > 0)
                                    @foreach($client['top_complements'] as $c)
                                        <tr>
                                            <td class="text-start ps-2">{{ $c['name'] }}</td>
                                            <td class="text-center fw-bold">{{ $c['total_quantity'] }}</td>
                                            <td class="text-end fw-bold">{{ \App\Helpers\FormatPrice::format($c['total_revenue']) }}</td>
                                        </tr>
                                    @endforeach
                                @else
                                    <tr>
                                        <td colspan="3" class="text-center text-muted small py-1">Aucun complément</td>
                                    </tr>
                                @endif
                                </tbody>
                            </table>
                        </div>

                        <div class="mb-3" style="width: 50%; float: left; padding: 5px;">
                            <table class="table table-sm table-bordered report-table mb-0 align-middle" style="font-size: 10px">
                                <thead>
                                <tr class="header-main text-center bg-light">
                                    <th colspan="3" class="text-uppercase text-dark fw-bold py-1" style="font-size: 3mm;">
                                        BOISSONS CHAUDES LES PLUS CONSOMMÉES
                                    </th>
                                </tr>
                                <tr class="header-sub text-center text-uppercase fw-bold bg-light" style="font-size: 2.5mm;">
                                    <th style="width: 50%">LIBELLE</th>
                                    <th style="width: 20%">QTÉ</th>
                                    <th style="width: 30%">MONTANT</th>
                                </tr>
                                </thead>
                                <tbody>
                                @if(!empty($client['top_drinks_from_complements']) && count($client['top_drinks_from_complements']) > 0)
                                    @foreach($client['top_drinks_from_complements'] as $b)
                                        <tr>
                                            <td class="text-start ps-2">{{ $b['name'] }}</td>
                                            <td class="text-center fw-bold">{{ $b['total_quantity'] }}</td>
                                            <td class="text-end fw-bold">{{ \App\Helpers\FormatPrice::format($b['total_revenue']) }}</td>
                                        </tr>
                                    @endforeach
                                @else
                                    <tr>
                                        <td colspan="3" class="text-center text-muted small py-1">Aucune boisson chaude</td>
                                    </tr>
                                @endif
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div style="clear: both;"></div>
                </div>
            </div>
        @endforeach
    @else
        <div class="card mb-2 p-2 border">
            <div class="card-body p-0">
                <div class="row">
                    <!-- 1. Menus vide -->
                    <div class="mb-3" style="width: 50%; float: left; padding: 5px;">
                        <table class="table table-sm table-bordered report-table mb-0 align-middle" style="font-size: 10px">
                            <thead>
                            <tr class="header-main text-center bg-light">
                                <th colspan="3" class="text-uppercase text-dark fw-bold py-1">MENUS LES PLUS CONSOMMÉS</th>
                            </tr>
                            <tr class="header-sub text-center text-uppercase fw-bold bg-light">
                                <th style="width: 50%">LIBELLE</th>
                                <th style="width: 20%">QTÉ</th>
                                <th style="width: 30%">MONTANT</th>
                            </tr>
                            </thead>
                            <tbody>
                            <tr>
                                <td colspan="3" class="text-center text-muted small py-2">Aucune donnée</td>
                            </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- 2. Boissons vide -->
                    <div class="mb-3" style="width: 50%; float: left; padding: 5px;">
                        <table class="table table-sm table-bordered report-table mb-0 align-middle" style="font-size: 10px">
                            <thead>
                            <tr class="header-main text-center bg-light">
                                <th colspan="3" class="text-uppercase text-dark fw-bold py-1">BOISSONS LES PLUS CONSOMMÉES</th>
                            </tr>
                            <tr class="header-sub text-center text-uppercase fw-bold bg-light">
                                <th style="width: 50%">LIBELLE</th>
                                <th style="width: 20%">QTÉ</th>
                                <th style="width: 30%">MONTANT</th>
                            </tr>
                            </thead>
                            <tbody>
                            <tr>
                                <td colspan="3" class="text-center text-muted small py-2">Aucune donnée</td>
                            </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- 3. Compléments vide -->
                    <div class="mb-3" style="width: 50%; float: left; padding: 5px;">
                        <table class="table table-sm table-bordered report-table mb-0 align-middle" style="font-size: 10px">
                            <thead>
                            <tr class="header-main text-center bg-light">
                                <th colspan="3" class="text-uppercase text-dark fw-bold py-1">COMPLÉMENTS LES PLUS CONSOMMÉS</th>
                            </tr>
                            <tr class="header-sub text-center text-uppercase fw-bold bg-light">
                                <th style="width: 50%">LIBELLE</th>
                                <th style="width: 20%">QTÉ</th>
                                <th style="width: 30%">MONTANT</th>
                            </tr>
                            </thead>
                            <tbody>
                            <tr>
                                <td colspan="3" class="text-center text-muted small py-2">Aucune donnée</td>
                            </tr>
                            </tbody>
                        </table>
                    </div>

                    <!-- 4. Boissons chaudes vide -->
                    <div class="mb-3" style="width: 50%; float: left; padding: 5px;">
                        <table class="table table-sm table-bordered report-table mb-0 align-middle" style="font-size: 10px">
                            <thead>
                            <tr class="header-main text-center bg-light">
                                <th colspan="3" class="text-uppercase text-dark fw-bold py-1">BOISSONS CHAUDES LES PLUS CONSOMMÉES</th>
                            </tr>
                            <tr class="header-sub text-center text-uppercase fw-bold bg-light">
                                <th style="width: 50%">LIBELLE</th>
                                <th style="width: 20%">QTÉ</th>
                                <th style="width: 30%">MONTANT</th>
                            </tr>
                            </thead>
                            <tbody>
                            <tr>
                                <td colspan="3" class="text-center text-muted small py-2">Aucune donnée</td>
                            </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div style="clear: both;"></div>
            </div>
        </div>
    @endif

</div>


</body>
</html>
