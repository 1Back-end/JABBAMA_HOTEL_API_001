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

{{-- Header --}}
<header class="text-center mb-3">
    <div class="fs-3 fw-bold text-uppercase">
        @if(!empty($warehouse))
            INVENTAIRE DE STOCKS DE L'{{ $warehouse->name }}
        @else
            INVENTAIRE DE TOUS LE STOCKS
        @endif
    </div>

    @if(!empty($start_date) && !empty($end_date))
        <div class="mt-1 text-muted">
            @if($start_date == $end_date)
                Date : <strong>{{ \Carbon\Carbon::parse($start_date)->format('d/m/Y') }}</strong>
            @else
                Période :
                <strong>{{ \Carbon\Carbon::parse($start_date)->format('d/m/Y') }}</strong>
                au
                <strong>{{ \Carbon\Carbon::parse($end_date)->format('d/m/Y') }}</strong>
            @endif
        </div>
    @endif
</header>



<div class="mt-2 w-100" style="border-top: 1px double rgba(0,0,0,0.75); margin-bottom: 2px"></div>
<div class="mb-2 w-100" style="border-top: 1px double rgba(0,0,0,0.75);"></div>



<p class="fst-italic text-end">
    Date d'impression : {{ now()->format('d/m/Y H:i') }}
</p>

<div class="d-flex justify-content-center mt-2">
    <table class="table table-bordered table-striped border-black" style="font-size: 11px;">
        <thead>
        <tr>
            <th>N°</th>
            <th>Réference</th>
            <th>Article</th>
            <th>Catégorie</th>
            <th>Unité</th>
            <th>Quantité</th>
        </tr>
        </thead>

        <tbody>
        @foreach ($product_points as $index => $item)
            <tr>
                <td>{{ $index + 1 }}</td>
                <td>{{ $item->product->code ?? '-' }}</td>
                <td>{{ $item->product->name ?? '-' }}</td>
                <td>{{ $item->product->category->name ?? '-' }}</td>
                <td>{{ $item->product->unitMeasure->name ?? '-' }}</td>
                <td class="fw-bold">{{ $item->quantity }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>


</body>
</html>
