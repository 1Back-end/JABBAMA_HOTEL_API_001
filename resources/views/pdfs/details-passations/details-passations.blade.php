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
        FICHE DE PASSATIONS DE STOCKS
    </div>
</header>

<div class="mt-2 w-100" style="border-top: 1px double rgba(0,0,0,0.75); margin-bottom: 2px"></div>
<div class="mb-2 w-100" style="border-top: 1px double rgba(0,0,0,0.75);"></div>


<div class="d-flex justify-content-between mb-3" style="font-size: 13px; gap: 2rem;">
    @if($passations->creator)
        <div>
            <div class="fs-6 fw-bold">Initier par :</div>
            <p class="m-0">
                <strong>Nom complet :</strong> <span
                    class="text-uppercase fw-bold fs-6">{{ $passations->creator?->nom_utilisateur ?? '—' }}</span>
            </p>
            <p class="m-0">
                <strong>Email :</strong> <span class="fw-bold fs-6">{{ $passations->creator?->email ?? '—' }}</span>
            </p>
        </div>
    @endif

    @if($passations->validator)
        <div>
            <div class="fs-6 fw-bold">Valider par :</div>
            <p class="m-0">
                <strong>Nom complet :</strong> <span
                    class="text-uppercase fw-bold fs-6">{{ $passations->validator?->nom_utilisateur ?? '—' }}</span>
            </p>
            <p class="m-0">
                <strong>Email :</strong> <span class="fw-bold fs-6">{{ $passations->validator?->email ?? '—' }}</span>
            </p>
        </div>
    @endif
</div>



<p class="fst-italic text-end">
    Date d'impression : {{ now()->format('d/m/Y H:i') }}
</p>

<div class="d-flex justify-content-center mt-2">
    <table class="table table-bordered table-striped  border-black" style="font-size: 11px;">
        <thead>
        <tr>
            <th colspan="12" class="text-center py-3" style="border-style: dotted">
                <strong>
                    PASSATION N°{{ $passations->reference }}
                </strong>
            </th>
        </tr>
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
        @foreach($passations->items as $index => $item)
        <tr>
            <td>{{ $index + 1 }}</td>
            <td>{{ $item->product->code ?? '---' }}</td>
            <td>{{ $item->product->name ?? '---' }}</td>
            <td>{{ $item->quantity_sent }}</td>
            <td>{{ $item->quantity_counted }}</td>
            <td>{{ $item->difference }}</td>
            <td>
                @if($item->status === 'ok')
                    <span class="text-center fw-bold text-success">Ok</span>
                @elseif($item->status === 'pending')
                    <span class="text-center fw-bold text-warning">En attente</span>
                @elseif($item->status === 'in_discuss')
                    <span class="text-center fw-bold text-danger">Non Ok</span>
                @else
                    <span style="color: gray;">N/A</span>
                @endif
            </td>

        </tr>
        @endforeach
        </tbody>
    </table>
</div>
<div class="d-flex align-content-center mb-3" style="font-size: 13px; gap: 2rem;">
    <p class="m-0">
        <strong>Entrepot :</strong>
        <span class="text-capitalize">{{ $passations->warehouse->name ?? '---' }}</span>
    </p>
    <p class="m-0">
        <strong>Statut :</strong>
        <span class="text-capitalize">{{ \App\Enums\PassationStatus::safeLabel($passations->status) }}</span>
    </p>
</div>
<div class="text-end mt-3" style="font-size: 13px;">
    <p>Fait le {{ $passations->created_at->format('d/m/Y H:i') }}</p>
</div>


</body>
</html>
