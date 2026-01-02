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
            font-weight: 400;
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
            display: table-header-group; /* Garde l'en-tête sur chaque page */
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

<header class="text-center">
    <div class="fs-3 fw-bold text-uppercase">
        RECAPITULATIF DU TABLEAU DE BORD

        @if(!empty($data['summary']['from']) && !empty($data['summary']['to']))
            <h3 style="font-style: italic; font-size: 11px;">
                Période : {{ $data['summary']['from'] }} → {{ $data['summary']['to'] }}
            </h3>
        @endif
    </div>
</header>

{{-- Ligne de séparation --}}
<div class="mt-2 w-100" style="border-top: 1px double rgba(0,0,0,0.75); margin-bottom: 2px;"></div>
<div class="mb-2 w-100" style="border-top: 1px double rgba(0,0,0,0.75);"></div>

{{-- Date d'impression --}}
<p class="fst-italic text-end" style="font-size: 12px;">
    Date d'impression : {{ now()->format('d/m/Y H:i') }}
</p>
<div class="col-12 mt-3">
    <div class="row g-3">

        {{-- Première moitié : Stock Adjustments --}}
        <div class="col-lg-6 col-sm-12 mb-3">
            <h5 class="fw-bold text-center mb-3" style="border-bottom: 2px dotted black; padding-bottom: 5px;">
                ÉTAT DES RÉGULARISATIONS DE STOCK
            </h5>
            <div class="d-flex flex-column gap-3">
                @foreach($data['stock_adjustments'] as $adjustment)
                    <div class="card shadow-sm border border-dark p-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="fw-semibold">{{ $adjustment['label'] }}</span>
                            <span class="badge bg-primary">{{ $adjustment['total'] }}</span>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Deuxième moitié : Supplies --}}
        <div class="col-lg-6 col-sm-12 mb-3">
            <h5 class="fw-bold text-center mb-3" style="border-bottom: 2px dotted black; padding-bottom: 5px;">
                ÉTAT DES APPROVISIONNEMENTS
            </h5>
            <div class="d-flex flex-column gap-3">
                @foreach($data['supplies'] as $supply)
                    <div class="card shadow-sm border border-dark p-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="fw-semibold">{{ $supply['label'] }}</span>
                            <span class="badge bg-success">{{ $supply['total'] }}</span>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Nouvelle ligne pour Purchase Orders si nécessaire --}}
        <div class="col-12 mt-3">
            <h5 class="fw-bold text-center mb-3" style="border-bottom: 2px dotted black; padding-bottom: 5px;">
                ÉTAT DES COMMANDES
            </h5>
            <div class="d-flex flex-column gap-3">
                @foreach($data['purchase_orders'] as $order)
                    <div class="card shadow-sm border border-dark p-3">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="fw-semibold">{{ $order['label'] }}</span>
                            <span class="badge bg-warning">{{ $order['total'] }}</span>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

    </div>
</div>






{{-- Footer --}}
<div class="text-end mt-4" style="font-size: 12px;">
    <p>Fait le {{ now()->format('d/m/Y H:i') }}</p>
</div>



</body>
</html>
