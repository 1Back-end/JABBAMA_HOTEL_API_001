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
            font-size: 2.8mm !important;
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
            border-collapse: collapse;
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

<header class="text-center mb-3">
    <div class="fs-4 fw-bold text-uppercase">
        RAPPORT GLOBAL - TAUX D'ÉCOULEMENT DE STOCKS
    </div>

    @if(!empty($start_date) && !empty($end_date))
        <h3 style="font-style: italic; font-size: 10px; margin-top: 3px;">
            Période : {{ $start_date }} au {{ $end_date }}
        </h3>
    @endif
</header>

<div class="mt-1 w-100" style="border-top: 1px double rgba(0,0,0,0.75); margin-bottom: 2px"></div>
<div class="mb-2 w-100" style="border-top: 1px double rgba(0,0,0,0.75);"></div>

<p class="fst-italic text-end" style="font-size: 10px; margin-bottom: 10px;">
    Date d'impression : {{ now()->format('d/m/Y H:i') }}
</p>

<div class="mb-4">
    <h5 class="fw-bold text-primary text-uppercase mb-2" style="font-size: 13px;">Cuisine</h5>

    @if(!empty($kitchen['info_stock_global']))
        <div class="alert alert-secondary py-1 px-2 small d-flex justify-content-between align-items-center mb-2" style="font-size: 9px; background-color: #f8f9fa; border: 1px solid #dee2e6;">
            <div>Entrepôt Cuisine : <strong>{{ $kitchen['info_stock_global']['entrepot_cuisine'] ?? 'N/A' }}</strong></div>
            <div>Produit de référence (Max) : <strong>{{ $kitchen['info_stock_global']['produit_le_plus_eleve'] ?? 'N/A' }}</strong></div>
            <div>Plafond (Valeur Max) : <strong>{{ $kitchen['info_stock_global']['plafond_valeur_max'] ?? 0 }}</strong></div>
        </div>
    @endif

    <div class="table-responsive">
        <table class="table table-sm table-bordered report-table mb-0 align-middle" style="font-size: 10px;">
            <thead>
            <tr class="header-sub text-center text-uppercase fw-bold bg-light">
                <th style="width: 5%">#</th>
                <th style="width: 25%">Agent (Serveur)</th>
                <th style="width: 15%">Plats Vendus</th>
                <th style="width: 15%">Points Réels</th>
                <th style="width: 15%">Points Max</th>
                <th style="width: 15%">Taux Global (%)</th>
                <th style="width: 20%">Statut</th>
            </tr>
            </thead>
            <tbody>
            @forelse($kitchen['performance'] as $index => $item)
                <tr>
                    <td class="text-center fw-bold">{{ $index + 1 }}</td>
                    <td class="text-start ps-2 fw-semibold text-uppercase text-primary">{{ $item['agent_name'] }}</td>
                    <td class="text-center">{{ $item['plats_vendus'] }}</td>
                    <td class="text-center fw-bold">{{ $item['points_reels'] }}</td>
                    <td class="text-center fw-bold">{{ $item['points_max'] }}</td>
                    <td class="text-center fw-bold text-success">{{ $item['taux_ecoulement'] }}</td>
                    <td class="text-center">
                            <span class="badge text-white px-2 py-1" style="background-color: {{ $item['badge_color'] }}; font-size: 9px;">
                                {{ $item['statut'] }}
                            </span>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="text-center text-muted py-2">Aucune donnée cuisine trouvée.</td>
                </tr>
            @endforelse

            @if(!empty($kitchen['performance']) && !empty($kitchen['general_average']))
                <tr class="table-secondary fw-bold" style="background-color: #e9ecef;">
                    <td colspan="2" class="text-end text-uppercase pe-2">Moyenne générale des agents :</td>
                    <td class="text-center">{{ $kitchen['general_average']['total_plats_vendus'] }}</td>
                    <td class="text-center">{{ $kitchen['general_average']['total_points_reels'] }}</td>
                    <td class="text-center">{{ $kitchen['general_average']['total_points_max'] }}</td>
                    <td class="text-center text-success">{{ $kitchen['general_average']['taux_ecoulement_global'] }}</td>
                    <td class="text-center">
                            <span class="badge text-white px-2 py-1" style="background-color: {{ $kitchen['general_average']['badge_color'] }}; font-size: 9px;">
                                {{ $kitchen['general_average']['statut'] }}
                            </span>
                    </td>
                </tr>
            @endif
            </tbody>
        </table>
    </div>
</div>


<!-- ========================================== -->
<!-- SECTION 2 : BAR DISTRIBUTION -->
<!-- ========================================== -->
<div class="mb-4">
    <h5 class="fw-bold text-primary text-uppercase mb-2" style="font-size: 13px;">Bar Distribution</h5>

    @if(!empty($distribution['info_stock_global']))
        <div class="alert alert-secondary py-1 px-2 small d-flex justify-content-between align-items-center mb-2" style="font-size: 9px; background-color: #f8f9fa; border: 1px solid #dee2e6;">
            <div>Entrepôt Distribution : <strong>{{ $distribution['info_stock_global']['entrepot'] ?? 'N/A' }}</strong></div>
            <div>Produit de référence (Max) : <strong>{{ $distribution['info_stock_global']['produit_le_plus_eleve'] ?? 'N/A' }}</strong></div>
            <div>Plafond (Valeur Max) : <strong>{{ $distribution['info_stock_global']['plafond_valeur_max'] ?? 0 }}</strong></div>
        </div>
    @endif

    <div class="table-responsive">
        <table class="table table-sm table-bordered report-table mb-0 align-middle" style="font-size: 10px;">
            <thead>
            <tr class="header-sub text-center text-uppercase fw-bold bg-light">
                <th style="width: 5%">#</th>
                <th style="width: 25%">Agent (Serveur)</th>
                <th style="width: 15%">Boissons Vendues</th>
                <th style="width: 15%">Points Réels</th>
                <th style="width: 15%">Points Max</th>
                <th style="width: 15%">Taux Global (%)</th>
                <th style="width: 20%">Statut</th>
            </tr>
            </thead>
            <tbody>
            @forelse($distribution['performance'] as $index => $item)
                <tr>
                    <td class="text-center fw-bold">{{ $index + 1 }}</td>
                    <td class="text-start ps-2 fw-semibold text-uppercase text-primary">{{ $item['agent_name'] }}</td>
                    <td class="text-center">{{ $item['boissons_vendues'] }}</td>
                    <td class="text-center fw-bold">{{ $item['points_reels'] }}</td>
                    <td class="text-center fw-bold">{{ $item['points_max'] }}</td>
                    <td class="text-center fw-bold text-success">{{ $item['taux_ecoulement'] }}</td>
                    <td class="text-center">
                            <span class="badge text-white px-2 py-1" style="background-color: {{ $item['badge_color'] }}; font-size: 9px;">
                                {{ $item['statut'] }}
                            </span>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="text-center text-muted py-2">Aucune donnée bar distribution trouvée.</td>
                </tr>
            @endforelse

            @if(!empty($distribution['performance']) && !empty($distribution['general_average']))
                <tr class="table-secondary fw-bold" style="background-color: #e9ecef;">
                    <td colspan="2" class="text-end text-uppercase pe-2">Moyenne générale des agents :</td>
                    <td class="text-center">{{ $distribution['general_average']['total_boissons_vendues'] }}</td>
                    <td class="text-center">{{ $distribution['general_average']['total_points_reels'] }}</td>
                    <td class="text-center">{{ $distribution['general_average']['total_points_max'] }}</td>
                    <td class="text-center text-success">{{ $distribution['general_average']['taux_ecoulement_global'] }}</td>
                    <td class="text-center">
                            <span class="badge text-white px-2 py-1" style="background-color: {{ $distribution['general_average']['badge_color'] }}; font-size: 9px;">
                                {{ $distribution['general_average']['statut'] }}
                            </span>
                    </td>
                </tr>
            @endif
            </tbody>
        </table>
    </div>
</div>


<!-- ========================================== -->
<!-- SECTION 3 : BAR TRANSFORMATION -->
<!-- ========================================== -->
<div class="mb-3">
    <h5 class="fw-bold text-primary text-uppercase mb-2" style="font-size: 13px;">Bar Transformation</h5>

    @if(!empty($transformation['info_stock_global']))
        <div class="alert alert-secondary py-1 px-2 small d-flex justify-content-between align-items-center mb-2" style="font-size: 9px; background-color: #f8f9fa; border: 1px solid #dee2e6;">
            <div>Entrepôt Transformation : <strong>{{ $transformation['info_stock_global']['entrepot'] ?? 'N/A' }}</strong></div>
            <div>Produit de référence (Max) : <strong>{{ $transformation['info_stock_global']['produit_le_plus_eleve'] ?? 'N/A' }}</strong></div>
            <div>Plafond (Valeur Max) : <strong>{{ $transformation['info_stock_global']['plafond_valeur_max'] ?? 0 }}</strong></div>
        </div>
    @endif

    <div class="table-responsive">
        <table class="table table-sm table-bordered report-table mb-0 align-middle" style="font-size: 10px;">
            <thead>
            <tr class="header-sub text-center text-uppercase fw-bold bg-light">
                <th style="width: 5%">#</th>
                <th style="width: 25%">Agent (Serveur)</th>
                <th style="width: 15%">Boissons Vendues</th>
                <th style="width: 15%">Points Réels</th>
                <th style="width: 15%">Points Max</th>
                <th style="width: 15%">Taux Global (%)</th>
                <th style="width: 20%">Statut</th>
            </tr>
            </thead>
            <tbody>
            @forelse($transformation['performance'] as $index => $item)
                <tr>
                    <td class="text-center fw-bold">{{ $index + 1 }}</td>
                    <td class="text-start ps-2 fw-semibold text-uppercase text-primary">{{ $item['agent_name'] }}</td>
                    <td class="text-center">{{ $item['boissons_vendues'] }}</td>
                    <td class="text-center fw-bold">{{ $item['points_reels'] }}</td>
                    <td class="text-center fw-bold">{{ $item['points_max'] }}</td>
                    <td class="text-center fw-bold text-success">{{ $item['taux_ecoulement'] }}</td>
                    <td class="text-center">
                            <span class="badge text-white px-2 py-1" style="background-color: {{ $item['badge_color'] }}; font-size: 9px;">
                                {{ $item['statut'] }}
                            </span>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="text-center text-muted py-2">Aucune donnée bar transformation trouvée.</td>
                </tr>
            @endforelse

            @if(!empty($transformation['performance']) && !empty($transformation['general_average']))
                <tr class="table-secondary fw-bold" style="background-color: #e9ecef;">
                    <td colspan="2" class="text-end text-uppercase pe-2">Moyenne générale des agents :</td>
                    <td class="text-center">{{ $transformation['general_average']['total_boissons_vendues'] }}</td>
                    <td class="text-center">{{ $transformation['general_average']['total_points_reels'] }}</td>
                    <td class="text-center">{{ $transformation['general_average']['total_points_max'] }}</td>
                    <td class="text-center text-success">{{ $transformation['general_average']['taux_ecoulement_global'] }}</td>
                    <td class="text-center">
                            <span class="badge text-white px-2 py-1" style="background-color: {{ $transformation['general_average']['badge_color'] }}; font-size: 9px;">
                                {{ $transformation['general_average']['statut'] }}
                            </span>
                    </td>
                </tr>
            @endif
            </tbody>
        </table>
    </div>
</div>


</body>
</html>
