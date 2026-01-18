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

<header class="text-center">
    <div class="fs-3 fw-bold text-uppercase">
        Rapports de passations de stocks

        {{-- Affiche le manager si filtré, sinon "Tous les agents" --}}
        @if(!empty($filter->agent_from_id) && $manager)
        <h2 style="font-style: italic;font-size: 12px">
            Agent : {{ $manager->nom_utilisateur }}
        </h2>
        @endif

        @if(!empty($filter->warehouse_uuid) && $warehouse)
            <h3 style="font-style: italic;font-size: 11px">
                Entrepot : {{ $warehouse->name }}
            </h3>
        @endif

        {{-- Affiche la période si définie --}}
        @if(!empty($filter->start_date) && !empty($filter->end_date))
            <h3 style="font-style: italic;font-size: 11px">
                Période : {{ $start_date }} au {{ $end_date }}
            </h3>
        @endif

        {{-- Affiche le statut si défini --}}
        @if(!empty($filter->status))
            <h3 style="font-style: italic;font-size: 11px">
                Statut : {{ \App\Enums\PassationStatus::safeLabel($filter->status) }}
            </h3>
        @endif

    </div>
</header>



<div class="mt-2 w-100" style="border-top: 1px double rgba(0,0,0,0.75); margin-bottom: 2px"></div>
<div class="mb-2 w-100" style="border-top: 1px double rgba(0,0,0,0.75);"></div>



<p class="fst-italic text-end">
    Date d'impression : {{ now()->format('d/m/Y H:i') }}
</p>

<div class="mt-2">
    @foreach($passations as $passation)

        <table class="table table-bordered table-striped border-black"
               style="font-size: 11px; width: 100%;">

            <thead>
            <tr>
                <th colspan="7"
                    class="text-center fw-bold py-2"
                    style="border: 2px dotted black;">
                    PASSATION N° {{ $passation->reference }} <br>
                </th>
            </tr>

            <tr>
                <th>N°</th>
                <th>Agent Initiateur</th>
                <th>Agent Recepteur(s)</th>
                <th>Entrepot</th>
                <th>Statut</th>
                <th>Crée le</th>
                <th>Modifié le</th>
                <th>Validé le</th>
            </tr>
            </thead>

            <tbody>
            <tr>
                <td>1</td>
                <td>{{ $passation->agentFrom->nom_utilisateur ?? '' }}</td>
                <td>
                    @if($passation->managers->isNotEmpty())
                        {{ $passation->managers->pluck('nom_utilisateur')->join(', ') }}
                    @else
                        ---
                    @endif
                </td>
                <td>{{ $passation->warehouse->name ?? '' }}</td>
                <td>{{ \App\Enums\PassationStatus::safeLabel($passation->status) }}</td>
                <td>{{ $passation->created_at->format('d/m/Y H:i') }}</td>
                <td>{{ $passation->updated_at->format('d/m/Y H:i') }}</td>
                <td>{{ $passation->updated_at->format('d/m/Y H:i') ?? $passation->validated_at->format('d/m/Y H:i')  }}</td>
            </tr>
            </tbody>

        </table>

    @endforeach
</div>

<div class="text-end mt-3" style="font-size: 13px;">
    <p>Fait le {{ $passation->created_at->format('d/m/Y H:i') }}</p>
</div>


</body>
</html>
