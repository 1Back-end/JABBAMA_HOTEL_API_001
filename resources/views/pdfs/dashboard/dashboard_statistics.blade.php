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
<div class="mt-3">

    <div class="col-lg-12 col-sm-12 mb-3">
        <div class="row">

            <div class="col-lg-6 col-sm-12">
                <div class="mb-2">
                    <h4 class="text-primary text-center text-uppercase fw-bold">
                        Statistiques sur les commandes
                    </h4>
                </div>

                <div class="d-flex flex-wrap gap-3 mx-3">
                    @foreach($data['purchase_orders'] as $order)
                        <span class="fw-semibold">
                    {{ $order['label'] }} ({{ $order['total'] }})
                 </span>
                    @endforeach
                </div>
            </div>


            <div class="col-lg-6 col-sm-12 mt-5">
                <div class="mb-2">
                    <h4 class="text-primary text-center text-uppercase fw-bold">
                        Statistiques sur les approvisionnements
                    </h4>
                </div>

                <div class="d-flex flex-wrap gap-3 mx-3">
                    @foreach($data['supplies'] as $supply)
                        <span class="fw-semibold">
                    {{ $supply['label'] }} ({{ $supply['total'] }})
                 </span>
                    @endforeach
                </div>
            </div>


            <div class="col-lg-6 col-sm-12 mt-5">
                <div class="mb-2">
                    <h4 class="text-primary text-center text-uppercase fw-bold">
                        Statistiques sur les RÉGULARISATIONS DE STOCK
                    </h4>
                </div>

                <div class="d-flex flex-wrap gap-3 mx-3 justify-content-center text-center">
                    @foreach($data['stock_adjustments'] as $adjustment)
                        <span class="fw-semibold">
                    {{ $adjustment['label'] }} ({{ $adjustment['total'] }})
                 </span>
                    @endforeach
                </div>
            </div>


            <div class="col-lg-6 col-sm-12 mt-5">
                <div class="mb-2">
                    <h4 class="text-primary text-center text-uppercase fw-bold">
                        Statistiques sur les ARTICLES LES PLUS CONSOMMÉS
                    </h4>
                </div>

                <div class="d-flex flex-wrap gap-3 justify-content-center text-center mx-5">

                    @foreach($data['most_consumed']['top'] as $product)
                        <span class="fw-semibold mx-3">
                        {{ $product->name }} ({{ $product->frequency }})
                    </span>
                    @endforeach

                    {{-- 🔹 SÉPARATEUR --}}
                    <span class="w-100 text-muted text-center">
                        ........................................................................
                    </span>

                    {{-- 🔻 BOTTOM 3 --}}
                    @foreach($data['most_consumed']['bottom'] as $product)
                        <span class="fw-semibold text-muted mx-3">
                            {{ $product->name }} ({{ $product->frequency }})
                        </span>
                    @endforeach

                </div>
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