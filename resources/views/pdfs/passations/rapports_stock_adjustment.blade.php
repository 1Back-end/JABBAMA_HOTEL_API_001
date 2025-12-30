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
        RAPPORT DE RÉGULARISATION DE STOCK
        <h2 style="font-style: italic;font-size: 12px">Action : {{ $action_label }}</h2>
        @if($start_date && $end_date)
            <h3 style="font-style: italic;font-size: 11px">
                Période : {{ $start_date }} au {{ $end_date }}
            </h3>
        @endif
    </div>
</header>


{{-- Ligne de séparation --}}
<div class="mt-2 w-100" style="border-top: 1px double rgba(0,0,0,0.75); margin-bottom: 2px"></div>
<div class="mb-2 w-100" style="border-top: 1px double rgba(0,0,0,0.75);"></div>

{{-- Date d'impression --}}
<p class="fst-italic text-end" style="font-size: 12px;">
    Date d'impression : {{ now()->format('d/m/Y H:i') }}
</p>

{{-- Boucle sur chaque StockAdjustment --}}
@foreach($stock_adjustments as $adjustment)
    <div class="d-flex justify-content-center mt-2">
        <table class="table table-bordered table-striped border-black" style="font-size: 12px; width: 100%;">
            <thead>
            <tr>
                <th colspan="5"
                    class="text-center fw-bold py-2"
                    style="border: 2px dotted black;">
                    REGULARISATION N° {{ $adjustment->reference ?? '---' }} <br>
                    <span style="font-size: 10px; font-weight: normal;">
                            Entrepôt : {{ $adjustment->warehouse->name ?? '---' }}
                        </span>
                </th>
            </tr>
            <tr>
                <th>N°</th>
                <th>Référence</th>
                <th>Article</th>
                <th>Description</th>
                <th>Quantité à régulariser</th>
            </tr>
            </thead>
            <tbody>
            @forelse($adjustment->items as $index => $item)
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td>{{ $item->product->code ?? '---' }}</td>
                    <td>{{ $item->product->name ?? '---' }}</td>
                    <td>{{ $item->product->description ?? '---' }}</td>
                    <td>{{ $item->quantity ?? 0 }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="12" class="text-center text-muted">Aucun article pour cette régularisation</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
@endforeach

{{-- Footer --}}

<div class="text-end mt-3" style="font-size: 12px;">
    <p>Fait le {{ now()->format('d/m/Y H:i') }}</p>
</div>



</body>
</html>
