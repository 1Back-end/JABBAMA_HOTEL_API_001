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

        /* Wrapper */
        .print-wrapper {
            position: relative;
        }

        /* Footer fixe */
        .print-footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            height: 10mm;
            text-align: center;
        }

        /* Pagination */
        .page-number:before {
            content: "Page " counter(page) " / " counter(pages);
        }

        h1 {
            font-size: 5mm !important;
        }

        /* ✅ TABLE : PAS DE SAUT FORCÉ */
        table {
            width: 100%;
            page-break-inside: avoid;   /* évite coupure interne */
            margin-bottom: 20px;        /* espace entre passations */
        }

        /* En-tête répété si coupure */
        thead {
            display: table-header-group;
        }

        tfoot {
            display: table-footer-group;
        }

        /* Lignes non coupées */
        tr {
            page-break-inside: avoid;
        }

        /* Images */
        img {
            max-width: 100%;
            height: auto;
        }

    </style>
</head>

<body>
{{-- Header --}}
<header class="text-center">
    <div class="fs-3 fw-bold text-uppercase">
        Passations articles groupés
    </div>

    <p style="font-style: italic; font-size: 12px; margin: 0;">
        Écarts de passations de stocks
    </p>

    <p style="font-style: italic; font-size: 11px; margin: 0;">
        Agent : <strong>{{ $manager->nom_utilisateur }}</strong>
    </p>

    @if($start_date && $end_date)
        <p style="font-style: italic; font-size: 11px; margin: 0;">
            Période : {{ $start_date }} au {{ $end_date }}
        </p>
    @endif
</header>

<div class="mt-2 w-100" style="border-top: 1px double rgba(0,0,0,0.75);"></div>
<div class="mb-2 w-100" style="border-top: 1px double rgba(0,0,0,0.75);"></div>

<p class="fst-italic text-end" style="font-size: 10px;">
    Date d'impression : {{ now()->format('d/m/Y H:i') }}
</p>

<div class="mt-2">
    <table class="table table-bordered table-striped border-black"
           style="font-size: 11px; width: 100%; border-collapse: collapse;">

        <thead>
        <tr class="text-center">
            <th style="width: 5%">N°</th>
            <th style="width: 15%">Code article</th>
            <th>Article</th>
            <th style="width: 15%">Qté transférée<br><small>(cumul)</small></th>
            <th style="width: 15%">Qté comptée<br><small>(cumul)</small></th>
            <th style="width: 10%">Écart total</th>
        </tr>
        </thead>

        <tbody>
        @foreach($items as $index => $item)
            <tr>
                <td class="text-center">{{ $index + 1 }}</td>
                <td>{{ $item['product_code'] }}</td>
                <td>{{ $item['product_name'] }}</td>
                <td class="text-end">{{ $item['quantity_sent'] }}</td>
                <td class="text-end">{{ $item['quantity_counted'] }}</td>
                <td class="text-end fw-bold"
                    style="color: {{ $item['difference'] != 0 ? '#c00' : '#198754' }}">
                    {{ $item['difference'] }}
                </td>
            </tr>
        @endforeach
        </tbody>

    </table>
</div>



</body>
</html>
