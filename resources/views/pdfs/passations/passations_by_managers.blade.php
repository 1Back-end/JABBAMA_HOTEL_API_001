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
<header class="text-center">
    <div class="fs-3 fw-bold text-uppercase">
        Écarts de passations de stocks
        <h2 style="font-style: italic;font-size: 12px">Agent : {{ $manager->nom_utilisateur }}</h2>
        @if($start_date && $end_date)
            <h3 style="font-style: italic;font-size: 11px">
                Période : {{ $start_date }} au {{ $end_date }}
            </h3>
        @endif
    </div>
</header>

<div class="mt-2 w-100" style="border-top: 1px double rgba(0,0,0,0.75); margin-bottom: 2px"></div>
<div class="mb-2 w-100" style="border-top: 1px double rgba(0,0,0,0.75);"></div>



<p class="fst-italic text-end">
    Date d'impression : {{ now()->format('d/m/Y H:i') }}
</p>

<div class="d-flex justify-content-center mt-2">
    @foreach($passations as $passation)
        <table class="table table-bordered table-striped border-black" style="font-size: 11px;">
            <thead>
            <tr>
                <th>N°</th>
                <th>Réference</th>
                <th>Article</th>
                <th>Qté transférée</th>
                <th>Qté comptée</th>
                <th>Ecart</th>
                <th>Statut</th>
            </tr>
            </thead>
            <tbody>
            @foreach($passation->items as $index => $item)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td>{{ $item->product->code ?? '---' }}</td>
                    <td>{{ $item->product->name ?? '---' }}</td>
                    <td>{{ $item->quantity_sent }}</td>
                    <td>{{ $item->quantity_counted }}</td>
                    <td>{{ $item->difference }}</td>
                    <td>
                        @if($item->status === 'ok')
                            <span class="text-success fw-bold">Ok</span>
                        @elseif($item->status === 'pending')
                            <span class="text-warning fw-bold">En attente</span>
                        @elseif($item->status === 'in_discuss')
                            <span class="text-danger fw-bold">Non Ok</span>
                        @else
                            <span style="color: gray;">N/A</span>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    @endforeach

</div>
<div class="text-end mt-3" style="font-size: 13px;">
    <p>Fait le {{ $passation->created_at->format('d/m/Y H:i') }}</p>
</div>


</body>
</html>
