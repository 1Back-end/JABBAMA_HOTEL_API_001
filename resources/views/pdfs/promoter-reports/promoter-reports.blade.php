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
            padding: 15px;
            font-size: 3mm !important;
            font-family: "Merriweather", serif;
            color: #333;
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

        .kpi-card {
            border-radius: 8px;
            padding: 12px;
            margin-bottom: 15px;
            border: 1px solid rgba(0,0,0,0.1);
        }
        .kpi-blue { background-color: #e7f1ff; border-color: #b6d4fe; }
        .kpi-red { background-color: #f8d7da; border-color: #f5c2c7; }
        .kpi-green { background-color: #d1e7dd; border-color: #badbcc; }
    </style>
</head>

<body>

{{-- Header --}}
<header class="text-center mb-3">
    <div class="fs-4 fw-bold text-uppercase">
        Rapport du Promoteur du {{ $parsedDate->format('d/m/Y') }}
    </div>
</header>


<div class="mt-2 w-100" style="border-top: 1px double rgba(0,0,0,0.75); margin-bottom: 5px"></div>
<div class="mb-4 w-100" style="border-top: 1px double rgba(0,0,0,0.75);"></div>
<div class="text-end mt-4" style="font-size: 11px;">
    <p>Date d'impression : {{ now()->format('d/m/Y H:i') }}</p>
</div>
<div class="row">

    <div class="col-6">
        <div class="kpi-card kpi-blue">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="fw-bold text-uppercase" style="font-size: 2.2mm;">CHIFFRE D'AFFAIRE ANNUEL :</span>
                <span class="fw-bold" style="font-size: 2.4mm;">{{ \App\Helpers\FormatPrice::format($chiffre_affaire_annuel) }}</span>
            </div>
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="fw-bold text-uppercase" style="font-size: 2.2mm;">Encaissement :</span>
                <span class="fw-bold" style="font-size: 2.4mm;">{{ \App\Helpers\FormatPrice::format($encaissement) }}</span>
            </div>
            <div class="d-flex justify-content-between align-items-center">
                <span class="fw-bold text-uppercase" style="font-size: 2.2mm;">Taux encaissement :</span>
                <span class="fw-bold" style="font-size: 2.4mm;">{{ $taux_encaissement }} %</span>
            </div>
        </div>
    </div>

    <!-- Charges Annuelles -->
    <div class="col-6">
        <div class="kpi-card kpi-red">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="fw-bold text-uppercase" style="font-size: 2.2mm;">Charges annuelles :</span>
                <span class="fw-bold" style="font-size: 2.4mm;">{{ \App\Helpers\FormatPrice::format($charges_annuelles) }}</span>
            </div>
            <div class="d-flex justify-content-between align-items-center mb-2" style="visibility: hidden;">
                <span style="font-size: 2.2mm;">-</span>
                <span style="font-size: 2.4mm;">-</span>
            </div>
            <div class="d-flex justify-content-between align-items-center">
                <span class="fw-bold text-uppercase" style="font-size: 2.2mm;">Taux de dépense :</span>
                <span class="fw-bold" style="font-size: 2.4mm;">{{ $taux_depense }} %</span>
            </div>
        </div>
    </div>

    <!-- Marge Brute -->
    <div class="col-6">
        <div class="kpi-card kpi-green">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="fw-bold text-uppercase" style="font-size: 2.2mm;">Marge brute annuelle :</span>
                <span class="fw-bold" style="font-size: 2.4mm;">{{ \App\Helpers\FormatPrice::format($marge_brute_annuelle) }}</span>
            </div>
            <div class="d-flex justify-content-between align-items-center mb-2" style="visibility: hidden;">
                <span style="font-size: 2.2mm;">-</span>
                <span style="font-size: 2.4mm;">-</span>
            </div>
            <div class="d-flex justify-content-between align-items-center">
                <span class="fw-bold text-uppercase" style="font-size: 2.2mm;">Taux marge brute :</span>
                <span class="fw-bold" style="font-size: 2.4mm;">{{ $taux_marge_brute }} %</span>
            </div>
        </div>
    </div>
    <div class="col-6">
        <div class="kpi-card kpi-blue">
            <div class="d-flex justify-content-between align-items-center mb-2" style="visibility: hidden;">
                <span style="font-size: 2.2mm;">-</span>
                <span style="font-size: 2.4mm;">-</span>
            </div>
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="fw-bold text-uppercase" style="font-size: 2.2mm;">Total des ventes :</span>
                <span class="fw-bold" style="font-size: 2.4mm;">{{ $totalVentes }}</span>
            </div>
            <div class="d-flex justify-content-between align-items-center" style="visibility: hidden;">
                <span style="font-size: 2.2mm;">-</span>
                <span style="font-size: 2.4mm;">-</span>
            </div>
        </div>
    </div>
</div>


<div class="table-responsive mt-1">
    <table class="table table-bordered table-striped text-center border-black" style="font-size: 11px;">
        <thead>
        <tr>
            <th colspan="4" class="bg-light text-uppercase">RESTAURANT</th>
        </tr>
        <tr>
            <th scope="col" style="width: 40%;" class="bg-light"></th>
            <th scope="col" style="width: 20%;">TOTAL DU JOUR</th>
            <th scope="col" style="width: 20%;">TOTAL DU MOIS</th>
            <th scope="col" style="width: 20%;">TOTAL ANNÉE</th>
        </tr>
        </thead>
        <tbody>
        <tr>
            <td class="fw-bold text-primary text-center bg-light">CHIFFRE D'AFFAIRE RESTAURANT</td>
            <td class="text-center">{{ \App\Helpers\FormatPrice::format($metricsJour['chiffre_affaire']) }}</td>
            <td class="text-center">{{ \App\Helpers\FormatPrice::format($metricsMois['chiffre_affaire']) }}</td>
            <td class="text-center">{{ \App\Helpers\FormatPrice::format($metricsAnnee['chiffre_affaire']) }}</td>
        </tr>
        <tr>
            <td class="fw-bold text-primary text-center bg-light">ENCAISSEMENT RESTAURANT</td>
            <td class="text-center">{{ \App\Helpers\FormatPrice::format($metricsJour['encaissement']) }}</td>
            <td class="text-center">{{ \App\Helpers\FormatPrice::format($metricsMois['encaissement']) }}</td>
            <td class="text-center">{{ \App\Helpers\FormatPrice::format($metricsAnnee['encaissement']) }}</td>
        </tr>
        <tr>
            <td class="fw-bold text-danger text-center bg-light">DÉPENSES RESTAURANT</td>
            <td class="text-center">{{ \App\Helpers\FormatPrice::format($metricsJour['depenses']) }}</td>
            <td class="text-center">{{ \App\Helpers\FormatPrice::format($metricsMois['depenses']) }}</td>
            <td class="text-center">{{ \App\Helpers\FormatPrice::format($metricsAnnee['depenses']) }}</td>
        </tr>
        <tr class="fw-bold">
            <td class="text-center text-success-emphasis bg-light">SOLDE RESTAURANT</td>
            <td class="text-center">{{ \App\Helpers\FormatPrice::format($metricsJour['solde']) }}</td>
            <td class="text-center">{{ \App\Helpers\FormatPrice::format($metricsMois['solde']) }}</td>
            <td class="text-center">{{ \App\Helpers\FormatPrice::format($metricsAnnee['solde']) }}</td>
        </tr>
        </tbody>
    </table>
</div>
<div class="table-responsive mt-1">
    <table class="table table-bordered table-striped text-center border-black" style="font-size: 11px;">
        <thead>
        <tr>
            <th colspan="4" class="bg-light text-uppercase">BAR</th>
        </tr>
        <tr>
            <th scope="col" style="width: 40%;" class="bg-light"></th>
            <th scope="col" style="width: 20%;">TOTAL DU JOUR</th>
            <th scope="col" style="width: 20%;">TOTAL DU MOIS</th>
            <th scope="col" style="width: 20%;">TOTAL ANNÉE</th>
        </tr>
        </thead>
        <tbody>
        <tr>
            <td class="fw-bold text-primary text-center bg-light">CHIFFRE D'AFFAIRE BAR</td>
            <td class="text-center">{{ \App\Helpers\FormatPrice::format($barJour['chiffre_affaire']) }}</td>
            <td class="text-center">{{ \App\Helpers\FormatPrice::format($barMois['chiffre_affaire']) }}</td>
            <td class="text-center">{{ \App\Helpers\FormatPrice::format($barAnnee['chiffre_affaire']) }}</td>
        </tr>
        <tr>
            <td class="fw-bold text-primary text-center bg-light">ENCAISSEMENT BAR</td>
            <td class="text-center">{{ \App\Helpers\FormatPrice::format($barJour['encaissement']) }}</td>
            <td class="text-center">{{ \App\Helpers\FormatPrice::format($barMois['encaissement']) }}</td>
            <td class="text-center">{{ \App\Helpers\FormatPrice::format($barAnnee['encaissement']) }}</td>
        </tr>
        <tr>
            <td class="fw-bold text-danger text-center bg-light">DÉPENSES BAR</td>
            <td class="text-center">{{ \App\Helpers\FormatPrice::format($barJour['depenses']) }}</td>
            <td class="text-center">{{ \App\Helpers\FormatPrice::format($barMois['depenses']) }}</td>
            <td class="text-center">{{ \App\Helpers\FormatPrice::format($barAnnee['depenses']) }}</td>
        </tr>
        <tr class="fw-bold">
            <td class="text-center text-success-emphasis bg-light">SOLDE BAR</td>
            <td class="text-center">{{ \App\Helpers\FormatPrice::format($barJour['solde']) }}</td>
            <td class="text-center">{{ \App\Helpers\FormatPrice::format($barMois['solde']) }}</td>
            <td class="text-center">{{ \App\Helpers\FormatPrice::format($barAnnee['solde']) }}</td>
        </tr>
        </tbody>
    </table>
</div>
<div class="table-responsive mt-1">
    <table class="table table-bordered table-striped text-center border-black" style="font-size: 11px;">
        <thead>
        <tr>
            <th colspan="4" class="text-uppercase">AUTRES</th>
        </tr>
        <tr>
            <th scope="col" style="width: 40%;" class="bg-light"></th>
            <th scope="col" style="width: 20%;">TOTAL DU JOUR</th>
            <th scope="col" style="width: 20%;">TOTAL DU MOIS</th>
            <th scope="col" style="width: 20%;">TOTAL ANNÉE</th>
        </tr>
        </thead>
        <tbody>
        <tr>
            <td class="fw-bold text-success text-center bg-light">AUTRES ENCAISSEMENTS</td>
            <td class="text-center">{{ \App\Helpers\FormatPrice::format($autresJour['encaissement']) }}</td>
            <td class="text-center">{{ \App\Helpers\FormatPrice::format($autresMois['encaissement']) }}</td>
            <td class="text-center">{{ \App\Helpers\FormatPrice::format($autresAnnee['encaissement']) }}</td>
        </tr>
        <tr>
            <td class="fw-bold text-danger text-center bg-light">AUTRES DÉPENSES</td>
            <td class="text-center">{{ \App\Helpers\FormatPrice::format($autresJour['depenses']) }}</td>
            <td class="text-center">{{ \App\Helpers\FormatPrice::format($autresMois['depenses']) }}</td>
            <td class="text-center">{{ \App\Helpers\FormatPrice::format($autresAnnee['depenses']) }}</td>
        </tr>
        </tbody>
    </table>
</div>
<div class="table-responsive mt-1">
    <table class="table table-bordered table-striped text-center border-black" style="font-size: 11px;">
        <thead>
        <tr>
            <th colspan="{{ count($caisseJour['caisses']) + 1 }}" class="bg-light text-uppercase">CAISSE</th>
        </tr>
        <tr>
            @foreach($caisseJour['caisses'] as $caisse)
                <th scope="col" style="width: {{ 100 / count($caisseJour['caisses']) }}%;">{{ strtoupper($caisse['label']) }}</th>
            @endforeach
            <th scope="col">SOLDE TOTAL</th>
        </tr>
        </thead>
        <tbody>
        <tr>
            @foreach($caisseJour['caisses'] as $caisse)
                <td class="text-center">{{ \App\Helpers\FormatPrice::format($caisse['solde']) }}</td>
            @endforeach
            <td class="fw-bold text-success-emphasis text-center">
                {{ \App\Helpers\FormatPrice::format($caisseJour['solde_total']) }}
            </td>
        </tr>
        </tbody>
    </table>
</div>


</body>
</html>
